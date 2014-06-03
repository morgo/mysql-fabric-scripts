mysql-fabric-scripts
====================

At this point the main script is a CloudFormation template generator which can be used with a command similar to:

php fabric.cfn.php  > stack.json && aws cloudformation create-stack --stack-name fabric1 --template-body file://stack.json  --parameters ParameterKey=AccessKey,ParameterValue=AKIAXXXXXXX ParameterKey=SecretKey,ParameterValue=JEp2xZXXXXXX ParameterKey=KeypairName,ParameterValue=ec2-keypair


