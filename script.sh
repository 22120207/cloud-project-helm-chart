
# Tag vpc
aws ec2 create-tags --resources subnet-047097a493cd4831a subnet-074b259b6e93f5315 subnet-0ff7ac635b710ae85 --tags Key=kubernetes.io/role/elb,Value=1

aws ec2 create-tags --resources subnet-0584f388ca671c05b subnet-0b462a318d4ff5e26 subnet-039ebfae92e83d83f --tags Key=kubernetes.io/role/internal-elb,Value=1

$cluster_name = "woocommerce-microservices"
# Get the OIDC ID by extracting the last segment of the OIDC issuer URL
$oidc_url = (aws eks describe-cluster --name $cluster_name --query "cluster.identity.oidc.issuer" --output text)
$oidc_id = ($oidc_url -split '/')[4]

# Associate IAM OIDC provider
eksctl utils associate-iam-oidc-provider --cluster $cluster_name --approve

# Create policy
$policy_name="RDSListReadWriteFullAccessPolicy"
aws iam create-policy --policy-name $policy_name --policy-document file://RDSListReadWriteFullAccessPolicy.json

# Create trust relationship & Role
$cluster_region = "us-east-1"
$cluster_name = "woocommerce-microservices"

$account_id = (aws sts get-caller-identity --query "Account" --output text)
$oidc_provider = (aws eks describe-cluster --name $cluster_name --region $cluster_region --query "cluster.identity.oidc.issuer" --output text) -replace "^https://", ""

$namespace = "web-app"
$service_account = "backend-service-account"
$role_name = "RDSListReadWriteFullAccessRole"

# Build keys with colon outside hashtable
$audKey = "$oidc_provider" + ":aud"
$subKey = "$oidc_provider" + ":sub"

$trustRelationship = @"
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": {
        "Federated": "arn:aws:iam::${account_id}:oidc-provider/${oidc_provider}"
      },
      "Action": "sts:AssumeRoleWithWebIdentity",
      "Condition": {
        "StringEquals": {
          "${oidc_provider}:aud": "sts.amazonaws.com",
          "${oidc_provider}:sub": "system:serviceaccount:${namespace}:${service_account}"
        }
      }
    }
  ]
}
"@

# Convert to JSON and write to file
Set-Content -Path "trust-relationship.json" -Value $trustRelationship

aws iam create-role --role-name $role_name --assume-role-policy-document file://trust-relationship.json

aws iam attach-role-policy --role-name $role_name --policy-arn=arn:aws:iam::$account_id:policy/

# Annotate Service Account
kubectl annotate serviceaccount -n $namespace $service_account eks.amazonaws.com/role-arn=arn:aws:iam::${account_id}:role/$role_name


sửa servername lại là ok