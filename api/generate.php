<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}

define('GROK_ENDPOINT','https://api.shortfactory.shop/grok/image');
define('GROK_CHAT','https://api.shortfactory.shop/grok/chat');
define('OUTPUT_DIR','/var/www/vhosts/shortfactory.shop/httpdocs/shorts/output/');
define('OUTPUT_URL','https://www.shortfactory.shop/shorts/output/');
define('MAX_SCENES',12);
define('SCENE_DURATION',10);

if(!is_dir(OUTPUT_DIR)){mkdir(OUTPUT_DIR,0755,true);}

$input=json_decode(file_get_contents('php://input'),true);
if(!$input||empty($input['script'])){
    echo json_encode(['error'=>'Missing script']);exit;
}

$script=trim($input['script']);
$style=$input['style']??'cinematic dark sci-fi';
$jobId=substr(md5(uniqid().microtime()),0,12);
$jobDir=OUTPUT_DIR.$jobId.'/';
mkdir($jobDir,0755,true);

file_put_contents($jobDir.'job.json',json_encode([
    'id'=>$jobId,'script'=>$script,'style'=>$style,
    'status'=>'splitting','created'=>date('c'),'scenes'=>[]
],JSON_PRETTY_PRINT));

$scenes=split_script($script);
if(count($scenes)>MAX_SCENES){$scenes=array_slice($scenes,0,MAX_SCENES);}

update_job($jobDir,'generating_images',['scene_count'=>count($scenes)]);

$imageFiles=[];
foreach($scenes as $i=>$line){
    $prompt="$style visual: $line. No text. Widescreen 16:9. High detail.";
    $imgData=grok_image($prompt);
    if($imgData){
        $imgFile=$jobDir."scene_".str_pad($i,2,'0',STR_PAD_LEFT).".png";
        file_put_contents($imgFile,$imgData);
        $imageFiles[]=$imgFile;
        update_job($jobDir,'generating_images',['images_done'=>$i+1,'total'=>count($scenes)]);
    }else{
        update_job($jobDir,'error',['message'=>"Failed to generate image for scene $i"]);
        echo json_encode(['error'=>"Image generation failed at scene $i",'job_id'=>$jobId]);exit;
    }
    usleep(500000);
}

update_job($jobDir,'adding_text');

foreach($scenes as $i=>$line){
    $imgFile=$jobDir."scene_".str_pad($i,2,'0',STR_PAD_LEFT).".png";
    $txtFile=$jobDir."scene_".str_pad($i,2,'0',STR_PAD_LEFT)."_txt.png";
    burn_text($imgFile,$txtFile,$line);
}

update_job($jobDir,'stitching');

$mp4File=$jobDir."short.mp4";
$success=stitch_video($jobDir,$scenes,$mp4File);

if($success){
    update_job($jobDir,'done',['url'=>OUTPUT_URL.$jobId.'/short.mp4','duration'=>count($scenes)*SCENE_DURATION]);
    echo json_encode([
        'job_id'=>$jobId,
        'status'=>'done',
        'url'=>OUTPUT_URL.$jobId.'/short.mp4',
        'scenes'=>count($scenes),
        'duration'=>count($scenes)*SCENE_DURATION
    ]);
}else{
    update_job($jobDir,'error',['message'=>'FFmpeg stitching failed']);
    echo json_encode(['error'=>'Video stitching failed','job_id'=>$jobId]);
}

// ========================
// FUNCTIONS
// ========================

function split_script($text){
    $lines=preg_split('/[.\n]+/',$text);
    $lines=array_map('trim',array_filter($lines,function($l){return strlen(trim($l))>5;}));
    return array_values($lines);
}

function grok_image($prompt){
    $ch=curl_init(GROK_ENDPOINT);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode(['prompt'=>$prompt]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_TIMEOUT=>60,
    ]);
    $resp=curl_exec($ch);
    $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($code!==200)return null;
    $data=json_decode($resp,true);
    if(isset($data['url'])){
        return file_get_contents($data['url']);
    }
    if(isset($data['b64_json'])){
        return base64_decode($data['b64_json']);
    }
    if(isset($data['image'])){
        return base64_decode($data['image']);
    }
    return null;
}

function burn_text($inFile,$outFile,$text){
    $text=str_replace("'","'\\''",strtoupper($text));
    $text=wordwrap($text,40,"\n",true);
    $cmd="ffmpeg -y -i ".escapeshellarg($inFile)
        ." -vf \"drawtext=text='$text':fontsize=36:fontcolor=white:borderw=3:bordercolor=black"
        .":x=(w-text_w)/2:y=h-th-60:font=Arial\" "
        .escapeshellarg($outFile)." 2>&1";
    exec($cmd,$out,$ret);
    if($ret!==0||!file_exists($outFile)){
        copy($inFile,$outFile);
    }
}

function stitch_video($jobDir,$scenes,$outputFile){
    $listFile=$jobDir.'filelist.txt';
    $list='';
    foreach($scenes as $i=>$line){
        $txtFile=$jobDir."scene_".str_pad($i,2,'0',STR_PAD_LEFT)."_txt.png";
        if(!file_exists($txtFile)){
            $txtFile=$jobDir."scene_".str_pad($i,2,'0',STR_PAD_LEFT).".png";
        }
        $list.="file '".basename($txtFile)."'\nduration ".SCENE_DURATION."\n";
    }
    $lastScene=$jobDir."scene_".str_pad(count($scenes)-1,2,'0',STR_PAD_LEFT)."_txt.png";
    if(!file_exists($lastScene))$lastScene=$jobDir."scene_".str_pad(count($scenes)-1,2,'0',STR_PAD_LEFT).".png";
    $list.="file '".basename($lastScene)."'\n";
    file_put_contents($listFile,$list);

    $cmd="cd ".escapeshellarg($jobDir)." && ffmpeg -y -f concat -safe 0 -i filelist.txt"
        ." -vf \"scale=1920:1080:force_original_aspect_ratio=decrease,pad=1920:1080:(ow-iw)/2:(oh-ih)/2:black,"
        ."zoompan=z='min(zoom+0.0008,1.08)':d=".SCENE_DURATION*25 .":s=1920x1080:fps=25\""
        ." -c:v libx264 -pix_fmt yuv420p -preset fast -crf 23"
        ." -t ".(count($scenes)*SCENE_DURATION)
        ." ".escapeshellarg($outputFile)." 2>&1";
    exec($cmd,$out,$ret);
    return $ret===0&&file_exists($outputFile);
}

function update_job($jobDir,$status,$extra=[]){
    $jobFile=$jobDir.'job.json';
    $job=json_decode(file_get_contents($jobFile),true);
    $job['status']=$status;
    $job['updated']=date('c');
    $job=array_merge($job,$extra);
    file_put_contents($jobFile,json_encode($job,JSON_PRETTY_PRINT));
}
