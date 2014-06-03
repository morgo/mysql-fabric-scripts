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

if (!$fabric_group) {
 echo "Sorry, could not find a Fabric Group that this server belongs to!";
 die();
}

/*
 Find the current state of the fabric group.
 If there are no instances in the group, then I can bootstrap myself empty
 and automatically join it.
*/

$servers_in_group = find_servers_in_fabric_group($fabric_group);

if (empty($servers_in_group)) {

 echo "# Allowing fabric access\n";
 echo "mysql -e \"CREATE USER 'fabric'@'%' IDENTIFIED BY 'secret';\"\n";
 echo "mysql -e \"GRANT ALL ON *.* TO 'fabric'@'%';\"\n";
 
 echo "# Sending Intructions to Register\n";
 echo "source  /etc/fabric-master-host\n";
 echo 'curl --silent http://$FABRIC_MASTER_HOST:8000/register.php' . "\n";

} else {

 /*
  This is the second use-case.  We need to find a machine and create a mysqldump
  from it, becoming a slave of it.
 */

 echo "# Other servers exist in group - finding suitable candidate to export data from\n";
 echo "# Piping in backup directly from a peer\n";
 $peer = $servers_in_group[0]['address'];
 list($peer_host, $peer_port) = explode(":", $peer);
 echo "mysqldump --single-transaction -h$peer_host -P $peer_port --master-data=1 -ufabric -psecret --all-databases --triggers --routines --events > /tmp/backup.sql\n";

 echo "# Restoring backup\n";
 echo "mysql < /tmp/backup.sql\n";

 echo "# Running FLUSH PRIVILEGES\n"; # restoring backup creates fabric grant, but as INSERT statement - so it will not be active.
 echo "mysql -e 'FLUSH PRIVILEGES';\n";

 echo "# Change master to MASTER_HOST\n";
 echo "echo \"CHANGE MASTER TO MASTER_HOST='$peer_host', MASTER_PORT=$peer_port, MASTER_USER='fabric', MASTER_PASSWORD='secret'\" | mysql\n";

 echo "# Starting Slave\n";
 echo "mysql -e 'START SLAVE';\n";

 echo "# Sending Intructions to Register\n";
 echo "source  /etc/fabric-master-host\n";
 echo 'curl --silent http://$FABRIC_MASTER_HOST:8000/register.php' . "\n";

}
