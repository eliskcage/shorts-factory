<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$jobId=preg_replace('/[^a-f0-9]/','',$_GET['id']??'');
if(!$jobId){echo json_encode(['error'=>'Missing job id']);exit;}

$jobFile='/var/www/vhosts/shortfactory.shop/httpdocs/shorts/output/'.$jobId.'/job.json';
if(!file_exists($jobFile)){echo json_encode(['error'=>'Job not found']);exit;}

echo file_get_contents($jobFile);
