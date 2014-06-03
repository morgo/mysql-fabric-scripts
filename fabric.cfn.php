<?php

/*

Example Usage:
php fabric.cfn.php  > stack.json && aws cloudformation create-stack --stack-name fabric1 \
--template-body file://stack.json  --parameters ParameterKey=AccessKey,ParameterValue=AKIAXXXXXXX \
ParameterKey=SecretKey,ParameterValue=JEp2xZXXXXXX ParameterKey=KeypairName,ParameterValue=ec2-keypair

*/

error_reporting(E_ALL);

function create_template($template) {

	if (PHP_MAJOR_VERSION == 5 && PHP_MINOR_VERSION == 4) {
		return json_encode($template, JSON_PRETTY_PRINT);
	} else {
		return json_encode($template);
	}

}

print create_template(array(
	'Description' => "Fabric master plus single HA group containing 1 initial node",
	'AWSTemplateFormatVersion' => '2010-09-09',
	"Parameters" => array(
		"KeypairName"     => array(
			"Type"        => "String",
			"Default"     => "ec2-keypair",
			"Description" => "Keypair to boot instances with"
		),
		"AccessKey"     => array(
			"Type"        => "String",
			"Default"     => "",
			"Description" => "Access Key used to by master to get meta-data on incoming requests"
		),
		"SecretKey"     => array(
			"Type"        => "String",
			"Default"     => "",
			"Description" => "Secret Key used to by master to get meta-data on incoming requests",
			"NoEcho"      => "true",
		),
	),
	'Resources' => array(
		'fabricmaster' => array(
			'Type' => "AWS::EC2::Instance",
			'Properties' => array(
				'InstanceType' => "m1.small",
				'ImageId' => 'ami-fb8e9292',
				'KeyName' => array('Ref' => 'KeypairName'),
				'UserData' => array('Fn::Base64' => array("Fn::Join" => array("", array("#!/bin/bash

ln -s \$0 /var/lib/cloud/bootstrap.sh

export FABRIC_MYSQL_DATABASE_NAME='fabric'
export FABRIC_MYSQL_USER='fabric'
export FABRIC_MYSQL_PASSWORD='secret'

# 1. Configure MySQL as a local backing store from Oracle official repos.

yum localinstall -y http://dev.mysql.com/get/mysql-community-release-el6-5.noarch.rpm
yum install -y mysql-community-server mysql-utilities mysql-connector-python
service mysqld start
chkconfig mysqld on

mysql -e \"CREATE USER '\$FABRIC_MYSQL_USER'@'localhost' IDENTIFIED BY '\$FABRIC_MYSQL_PASSWORD';\"
mysql -e \"GRANT ALL ON \$FABRIC_MYSQL_DATABASE_NAME.* TO '\$FABRIC_MYSQL_USER'@'localhost';\"

# I'm not sure what was there, but we need to write out a fabric config
# File with this secret password

cat > /etc/mysql/fabric.cfg << EOF
[DEFAULT]
prefix =
sysconfdir = /etc
logdir = /var/log

[logging]
url = file:///var/log/fabric.log
level = INFO

[storage]
auth_plugin = mysql_native_password
database = \$FABRIC_MYSQL_DATABASE_NAME
user = \$FABRIC_MYSQL_USER
address = localhost:3306
connection_delay = 1
connection_timeout = 6
password = secret
connection_attempts = 6

[failure_tracking]
notification_interval = 60
notification_clients = 50
detection_timeout = 1
detection_interval = 6
notifications = 300
detections = 3
failover_interval = 0
prune_time = 3600

[servers]
user = \$FABRIC_MYSQL_USER
password = \$FABRIC_MYSQL_PASSWORD

[connector]
ttl = 1

[protocol.xmlrpc]
disable_authentication = no
ssl_cert =
realm = MySQL Fabric
ssl_key =
ssl_ca =
threads = 5
user = admin
password = \$FABRIC_MYSQL_PASSWORD
address = localhost:32274

[executor]
executors = 5

[sharding]
mysqldump_program = /usr/bin/mysqldump
mysqlclient_program = /usr/bin/mysql
EOF

# Initialize Fabric Structure.
mysqlfabric manage setup

# Start Fabric Daemon
# Daemonize is broken by BUG #72818
# So nest it inside of supervisord for startup/process monitoring.

easy_install supervisor
echo_supervisord_conf > /etc/supervisord.conf

cat >> /etc/supervisord.conf << EOF

[program:mysqlfabricd]
command=mysqlfabric manage start

EOF

# start supervisord
supervisord -c /etc/supervisord.conf

sleep 3

# Create the global MySQL Fabric Group
mysqlfabric group create GLOBAL1

mkdir -p /root/.aws/
cat > /root/.aws/config << EOF
[default]
aws_access_key_id = ", array("Ref" => "AccessKey"),"
aws_secret_access_key = ", array("Ref" => "SecretKey"), "
region = us-east-1
EOF

# Start a webserver on port 8000 to listen for HA group GLOBAL1 to phone home on.
# Eventually this could be replaced by the xmlrpc protocol.

yum install -y php54

mkdir -p /usr/local/bin/scripts/
cat > /usr/local/bin/scripts/bootstrap.php << EOF
".str_replace('$', '\$', file_get_contents("bootstrap.php"))."
EOF

cat > /usr/local/bin/scripts/register.php << EOF
".str_replace('$', '\$', file_get_contents("register.php"))."
EOF

cat > /usr/local/bin/bootstrap-start.sh << EOF
cd /usr/local/bin/scripts
php -S 0.0.0.0:8000 2>&1 >> /tmp/error.txt
EOF

sh /usr/local/bin/bootstrap-start.sh &

")))),
			)
		),
		'fabricglobal1LC' => array(
			'Type' => 'AWS::AutoScaling::LaunchConfiguration',
			'Properties' => array(
				'InstanceType' => "m1.small",
				'ImageId' => 'ami-fb8e9292',
				'KeyName' => array('Ref' => 'KeypairName'),
				'UserData' => array('Fn::Base64' => array("Fn::Join" => array("", array("#!/bin/bash
cat > /etc/fabric-master-host << EOF
export FABRIC_MASTER_HOST='", array('Fn::GetAtt' => array('fabricmaster', 'PrivateIp')),"'
EOF

yum localinstall -y http://dev.mysql.com/get/mysql-community-release-el6-5.noarch.rpm
yum install -y mysql-community-server

# Write a new my.cnf file, since patching the existing one is difficult.
# Configure GTIDs, binlogging, log-slave-updates, server-id and enforce-gtid-consistency

cat > /etc/my.cnf << EOF
# For advice on how to change settings please see
# http://dev.mysql.com/doc/refman/5.6/en/server-configuration-defaults.html

[mysqld_safe]
log-error=/var/log/mysqld.log
pid-file=/var/run/mysqld/mysqld.pid

[mysqld]
#
# Remove leading # and set to the amount of RAM for the most important data
# cache in MySQL. Start at 70% of total RAM for dedicated server, else 10%.
# innodb_buffer_pool_size = 128M
#
# Remove leading # to turn on a very important data integrity option: logging
# changes to the binary log between backups.
# log_bin
#
# Remove leading # to set options mainly useful for reporting servers.
# The server defaults are faster for transactions and fast SELECTs.
# Adjust sizes as needed, experiment to find the optimal values.
# join_buffer_size = 128M
# sort_buffer_size = 2M
# read_rnd_buffer_size = 2M
datadir=/var/lib/mysql
socket=/var/lib/mysql/mysql.sock

# Disabling symbolic-links is recommended to prevent assorted security risks
symbolic-links=0

# Recommended in standard MySQL setup
sql_mode=NO_ENGINE_SUBSTITUTION,STRICT_TRANS_TABLES

# Fabric specific settings
gtid_mode=ON
log-bin
log-slave-updates
enforce-gtid-consistency

EOF

service mysqld start
chkconfig mysqld on

# Generate a unique server ID from our IPv4 Address
# This is a bit of a hack, but should work with one local IP address on AWS.

IPADDRESS=`ifconfig | grep 'inet addr' | grep -v '127.0.0.1' | awk '{print \$2}' | sed 's/addr://'`;
SERVERID=`mysql -BNe \"select inet_aton('\$IPADDRESS');\"`
echo \"server-id=\$SERVERID\" >> /etc/my.cnf
mysql -e \"SET GLOBAL server_id=\$SERVERID\"

# Basic Initialization is complete.  Bootstrap back to the fabric daemon 
# in the background, so as to not fail the health check.  There may be
# a long wait, since it's possible that the master itself may be taking a while
# to come online.

cat > /usr/local/bin/bootstrap-from-fabric-master.sh << EOF
#!/bin/sh
source  /etc/fabric-master-host

while true; do
 curl --silent http://\\\$FABRIC_MASTER_HOST:8000/bootstrap.php  > /tmp/bootstrap.sh
 rc=\\\$?
 if [[ \\\$rc -eq 0 ]] ; then
  break; // Master is up, continue
 fi
  echo \"Sleeping 10 seconds - waiting for the master to come up...\";
  sleep 10;
done;

# Execute the one-time bootstrap that the master has sent to us.
sh /tmp/bootstrap.sh

EOF

sh /usr/local/bin/bootstrap-from-fabric-master.sh &")))),
)
		),
		'fabricglobalASG' => array(
			'Type' => 'AWS::AutoScaling::AutoScalingGroup',
			'Properties' => array(
				'LaunchConfigurationName' => array('Ref' => 'fabricglobal1LC'),
				'MinSize' => 1,
				'MaxSize' => 1,
				'AvailabilityZones' => array('Fn::GetAZs' => 'us-east-1'),
				'Tags' => array(array(
					'Key' => 'FABRIC_GROUP',
					'Value' => 'GLOBAL1',
					"PropagateAtLaunch" => "true",
				)),
			),
		),
	)
));

