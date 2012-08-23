<VirtualHost *:80>
    ServerAdmin maywza@gmail.com
    ServerName  www.komodo.com
    DocumentRoot /var/www/www.komodo.com

    <Directory /var/www/www.komodo.com>
        Order allow,deny
        allow from all
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/www.komodo.com.error.log

    # Possible values include: debug, info, notice, warn, error, crit,
    # alert, emerg.
    #LogLevel warn

    CustomLog ${APACHE_LOG_DIR}/www.komodo.com.access.log combined
</VirtualHost>
