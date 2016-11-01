<?php
date_default_timezone_set('America/New_York');

require 'vendor/autoload.php';

use Aws\Ec2\Ec2Client;
use Aws\S3\S3Client;
use Aws\Rds\RdsClient;

/* 
 * Get details about this specific machine instance
 */
$instance_metadata = `curl http://169.254.169.254/latest/dynamic/instance-identity/document`;
$metadata_hash = json_decode ($instance_metadata, true);

$instance_id = $metadata_hash['instanceId'];
$availability_zone = $metadata_hash['availabilityZone'];
$demo_region = $metadata_hash['region']; 

/* 
 * Set up EC2, S3 API clients
 */
$my_role = 'ec2_s3_rds';
$creds = `curl http://169.254.169.254/latest/meta-data/iam/security-credentials/$my_role`;
$cred_hash = json_decode($creds, true);
$key_id = $cred_hash['AccessKeyId'];
$secret_key = $cred_hash['SecretAccessKey'];

try {
  $ec2_client = Ec2Client::factory(array (
    'key' => $key_id,
    'secret' => $secret_key,
    'region'  => $demo_region,
    'version' => '2016-09-15'
  ));

  $s3_client = S3Client::factory(array (
    'key' => $key_id,
    'secret' => $secret_key,
    'region' => $demo_region,
    'version' => '2006-03-01'
  ));

  $rds_client = RdsClient::factory(array (
    'key' => $key_id,
    'secret' => $secret_key,
    'region' => $demo_region,
    'version' => '2014-10-31'
  ));

  $instance_data = $ec2_client->describeInstances(array (
    'InstanceIds' => array ($instance_id),
  ));   // class Aws\Result

  $this_instance = $instance_data->toArray()['Reservations'][0]['Instances'][0];
  $public_dns = $this_instance['PublicDnsName'];
  $instance_type = $this_instance['InstanceType'];

 
  $target_db_tag = 'demo-db-machine'; 
  $db_data = $rds_client->describeDBInstances();
  $db_host = FALSE;
  $db_instances = $db_data->toArray()['DBInstances'];

  $num_instances = count ($db_instances);
  for ($i = 0; $i < $num_instances; $i++) {
    $this_instance = $db_instances[$i];
    $inst_id = $this_instance['DBInstanceIdentifier'];
    if ($inst_id == $target_db_tag) {
      $db_host =  $this_instance['Endpoint']['Address'];
      break;
    }
  }



} catch (Exception $e) {
  print "Exception: " . $e->getMessage();
}


/*
 * Get the list of images for this instance,
 * from that get a single random image.
 */

$s3_folders = array (
  'jwst',
  'stonehenge',
  'confetti',
);

$sub_id = substr ($instance_id, -6, 6);

$error = FALSE;
$folder = $s3_folders[0]; // default in case anything goes wrong


// If the instance comes up in us-west-2a it will go into single-zone mode,
// otherwise it will use the zone (2b/2c) to choose between photo folders
$IS_SINGLE_ZONE = ($availability_zone == 'us-west-2b') ? TRUE : FALSE;
$id_as_decimal = -1;
$folder_id = -1;

if ($IS_SINGLE_ZONE) {
  // Select a folder based on the instance id
  $id_as_decimal = hexdec ($sub_id);
  $folder_id = $id_as_decimal % (count ($s3_folders));
  $folder = $s3_folders[$folder_id];
} else {
  // Select a folder based on the availability zone
  switch ($availability_zone) {
    case 'us-west-2a':
      $folder = $s3_folders[0];
      break; 
    case 'us-west-2b':
      $folder = $s3_folders[1];
      break;
    case 'us-west-2c':
      $folder = $s3_folders[2];
      break;
    default:
      $error = "Unknown zone $availability_zone";
  }
}

$iterator = $s3_client->getIterator('ListObjects', array(
    'Bucket' => 'gsfcdemo',
    'Prefix' => $folder
));

$images = array ();

foreach ($iterator as $object) {
    if (stripos ($object['Key'], 'jpg') === FALSE) continue; // skip directory entry itself
    array_push ($images, $object['Key']);
}

$num_images = count ($images);
$image_idx = rand (0, ($num_images - 1));

$this_img = $images[$image_idx];

$instance_color = $sub_id;

$db_time = 'Unable to access DB time';
//$db_host = 'demo-db-machine.ciqx20umqbsu.us-west-2.rds.amazonaws.com';

/*
 * Determine DB host from SDK
 */

$dbh = new PDO("mysql:host=$db_host;port=3306;dbname=demo_db;charset=utf8", 'gsfcdemo', 'demoadmin');
$rs = $dbh->query('SELECT NOW() AS this_time');

$row = $rs->fetch();
if ($row) {
  $db_time = $row['this_time'];
}


print <<<END_HTML
<html>
<head>
<link rel='stylesheet' href='style.css' type='text/css'>
</head>
<body>

<div class='instance-info'>
 <p>Instance ID: $instance_id <span style='background-color: #$instance_color'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span> Type: <span class='info'>$instance_type</span></p>
 <p>Public DNS: <span class='info'>$public_dns</span> Availability zone: <span class='info'>$availability_zone</span></p>
 <p>Image folder: <span class='info'>$folder</span></p> 
 <p>DB host: <span class='info'>$db_host</span></p> 
 <p>DB time: <span class='info'>$db_time</span></p> 
</div>
<div class='s3-image'>
 <img src='https://s3-$demo_region.amazonaws.com/gsfcdemo/$this_img'/>
</div>

</body>
</html>
END_HTML;
