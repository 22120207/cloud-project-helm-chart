1. RDS

user: dbmasteruser
pass: SecurePass123!

command to connect:
/opt/bitnami/mariadb/bin/mariadb -h woocommerce-rds-db.cyvm00km2pgg.us-east-1.rds.amazonaws.com -P 3306 -u dbmasteruser -p --ssl --ssl-ca=/opt/bitnami/mariadb/certs/rds-ca.pem

vi /opt/bitnami/mariadb/certs/rds-ca.pem

define('DB_NAME', 'woocommerce_db');
define('DB_USER', 'dbmasteruser');
define('DB_PASSWORD', 'SecurePass123!');
define('DB_HOST', 'woocommerce-rds-db.cyvm00km2pgg.us-east-1.rds.amazonaws.com:3306');

# Migrate

/opt/bitnami/mariadb/bin/mariadb-dump -u bn_wordpress --databases bitnami_wordpress --single-transaction --compress --order-by-primary -p3e14339097632528c8673136c7edb4f14ef4a3c6d061a794a65194ad10ba0cef | sudo /opt/bitnami/mariadb/bin/mariadb -u dbmasteruser --host woocommerce-rds-db.cyvm00km2pgg.us-east-1.rds.amazonaws.com woocommerce_db -p --ssl --ssl-ca=/opt/bitnami/mariadb/certs/rds-ca.pem

/opt/bitnami/mariadb/bin/mariadb -u dbmasteruser --host woocommerce-rds-db.cyvm00km2pgg.us-east-1.rds.amazonaws.com -pSecurePass123! --ssl --ssl-ca=/opt/bitnami/mariadb/certs/rds-ca.pem -N -e "
SELECT CONCAT('CREATE TABLE woocommerce_db.', table_name, ' LIKE bitnami_wordpress.', table_name, ';')
FROM information_schema.tables
WHERE table_schema = 'bitnami_wordpress';
" | /opt/bitnami/mariadb/bin/mariadb -u dbmasteruser --host woocommerce-rds-db.cyvm00km2pgg.us-east-1.rds.amazonaws.com -pSecurePass123! --ssl --ssl-ca=/opt/bitnami/mariadb/certs/rds-ca.pem

/opt/bitnami/mariadb/bin/mariadb -u dbmasteruser --host woocommerce-rds-db.cyvm00km2pgg.us-east-1.rds.amazonaws.com -pSecurePass123! --ssl --ssl-ca=/opt/bitnami/mariadb/certs/rds-ca.pem -N -e "
SELECT CONCAT('INSERT INTO woocommerce_db.', table_name, ' SELECT \* FROM bitnami_wordpress.', table_name, ';')
FROM information_schema.tables
WHERE table_schema = 'bitnami_wordpress';
" | /opt/bitnami/mariadb/bin/mariadb -u dbmasteruser --host woocommerce-rds-db.cyvm00km2pgg.us-east-1.rds.amazonaws.com -pSecurePass123! --ssl --ssl-ca=/opt/bitnami/mariadb/certs/rds-ca.pem

cp /opt/bitnami/wordpress/wp-config.php /opt/bitnami/wordpress/wp-config.php-backup
sudo chmod 664 /opt/bitnami/wordpress/wp-config.php
sudo /opt/bitnami/ctlscript.sh restart
