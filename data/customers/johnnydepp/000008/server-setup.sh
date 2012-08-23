#!/bin/bash
cd /tmp
wget http://www.magentocommerce.com/downloads/assets/1.7.0.2/magento-1.7.0.2.tar.bz2
tar xjf magento-1.7.0.2.tar.gz

sudo chown ubuntu:ubuntu /var/www
sudo rm /var/www/index.html
sudo mv /tmp/magento /var/www/www.komodo.com
sudo chown ubuntu:ubuntu www.komodo.com
sudo chmod 755 www.komodo.com

cd www.komodo.com
chmod -R o+w media var
chmod o+w app/etc

sudo a2dissite 000-default
sudo a2ensite www.komodo.com
sudo a2enmod cache
sudo a2enmod headers
sudo a2enmod expires
sudo a2enmod rewrite
sudo a2enmod ssl
sudo service apache2 restart

mysqladmin -uroot create magento

php -f install.php -- --license_agreement_accepted yes \
--locale "th_TH"
--timezone "Asia/Bangkok" \
--default_currency "GBP" \
--db_host localhost \
--db_name magento \
--db_user root \
--db_pass "%db-pass%" \
--db_prefix magento_ \
--skip_url_validation yes \
--url "www.komodo.com" \
--secure_base_url "https://www.komodo.com" \
--use_rewrites yes \
--use_secure no \
--use_secure_admin no \
--enable_charts yes \
--session_save files \
--admin_frontname "admin" \
--admin_firstname "Suthinaan" \
--admin_lastname "%admin-lastname%" \
--admin_email "kai-shiden@rcomm" \
--admin_username "admin" \
--admin_password "hydra1234" \
--encryption_key "b1db3dedbdb8f5e431a13353f6a1bbd7"
