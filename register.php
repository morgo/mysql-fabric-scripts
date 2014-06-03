<?php

/*
 Looks up the instance that is requesting this page, and then determines
 how to create a custom bootstrap to respond.
*/

function find_my_fabric_ha_group($server) {
 $metadata = json_decode(shell_exec("aws ec2 describe-instances --filters Name=private-ip-address,Values=$server"));
 foreach($metadata->Reservations[0]->Instances[0]->Tags as $array) {
  if ($array->Key=='FABRIC_GROUP') {
   return $array->Value;
  }
 }
}

function find_servers_in_fabric_group($group_name) {
 // http://bugs.mysql.com/bug.php?id=71445 : NOT Valid JSON
 $metadata = str_replace("  return      =", "", shell_exec("/usr/bin/mysqlfabric group lookup_servers $group_name | grep 'return      ='"));
 $metadata = str_replace("'", '"', $metadata); // JSON requires double-quotes.
 return json_decode($metadata, true);
}

// ----------------------------------------------------------------------

$server = $_SERVER['REMOTE_ADDR'];
$fabric_group = find_my_fabric_ha_group($server);
$servers_in_group = find_servers_in_fabric_group($fabric_group);

if (!$fabric_group) {
 echo "Sorry, could not find a Fabric Group that this server belongs to!";
 die();
}

shell_exec("/usr/bin/mysqlfabric group add $fabric_group $server:3306\n");

if (empty($servers_in_group)) {
 shell_exec("/usr/bin/mysqlfabric group promote $fabric_group\n");
}

?>