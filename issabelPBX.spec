Name:           issabelPBX
Version:        2.13.0
Release:        5
Summary:        issabelPBX

License:        GPL
URL:            www.issabel.org
Source0:        issabelPBX-2.13.0.tar.gz
BuildArch:      noarch

BuildRequires:  bash php-cli
Requires(pre):  asterisk
Requires:       php-cli php-mysqlnd php-mbstring mariadb perl-Digest-MD5 perl-URI perl-libwww-perl sox mpg123 perl-LWP-Protocol-https
Requires:       gettext
# issabelpbx_db extends the PEAR DB class (issabelpbx_DB.php:3), and
# db_connect.php requires DB.php on every admin page via bootstrap.php.
# Without /usr/share/pear/DB.php the whole GUI dies with
# 'Class "DB" not found'. No EL10 package provides it
# (dnf provides '*/pear/DB.php' -> no matches), so issabel-pear-DB ships it.
Requires:       issabel-pear-DB

%undefine __brp_mangle_shebangs
%define debug_package %{nil}

%description
issabelPBX

%prep
%setup -q

%build

%install
mkdir -p %{buildroot}/usr/src/issabelPBX
cp -a * %{buildroot}/usr/src/issabelPBX

mkdir -p  %{buildroot}/etc/logrotate.d/
cp        %{buildroot}/usr/src/issabelPBX/build/5.0/files/issabelpbx.logrotate  %{buildroot}/etc/logrotate.d/
chmod 644 %{buildroot}/etc/logrotate.d/*

%post

PODIR=/usr/src/issabelPBX

killall -q -0 asterisk
if [ $? -eq 0 ]; then
echo "asterisk is up and running"
killall -q -0 mysqld
if [ $? -eq 0 ]; then
echo "mariadb is up and running"
echo "perform installation"
if [ $? -eq 1 ]; then
/usr/src/issabelPBX/framework/install_amp --dbuser=root --installdb --scripted --language=en || :
else
MYSQL_ROOTPWD=`grep mysqlrootpwd= /etc/issabel.conf | sed 's/^mysqlrootpwd=//'`
/usr/src/issabelPBX/framework/install_amp --dbuser=root --dbpass=$MYSQL_ROOTPWD --scripted --language=en || :
fi
PODIR=/var/www/html/admin
else
echo "mariadb is not running, installation process has been skipped"
touch /installamp
fi
else
echo "asterisk is not running, installation process has been skipped"
touch /installamp
fi

# Compile .po files to .mo
for A in `find $PODIR -name \*.po`
do
POFILE=${A}
MOFILE=${POFILE%.po}.mo
PODIR=${A%$POFILE}
if ! sudo -u asterisk msgfmt $POFILE -o $MOFILE; then
    echo "WARNING: msgfmt failed for $POFILE (catalog not compiled)" >&2
fi
done

chmod 644 /etc/logrotate.d/issabelpbx.logrotate

%files
/usr/src/issabelPBX/
%config(noreplace) /etc/logrotate.d/issabelpbx.logrotate

%doc

%changelog
* Sat Oct 10 2026 Issabel Foundation <info@issabel.org> - 2.13.0-5
- Make the PBX admin work offline: vendor Font Awesome 4.7.0, Inter/Noto Sans
  fonts and jQuery UI 1.8.9 locally, and drop the external CDNs (maxcdn,
  googleapis, Google Fonts) from header.php/issabelpbx.php.

* Sat Oct 10 2026 Issabel Foundation <info@issabel.org> - 2.13.0-4
- Add the "Modern" theme bridge (modern-bridge.css + header.php detection): when
  the issabel-framework "modern" theme is installed, the PBX admin adopts the
  Issabel brand and follows the framework light/dark mode (localStorage/postMessage).

* Fri Oct 09 2026 Issabel Foundation <info@issabel.org> - 2.13.0-3
- Add the missing gettext header (charset=UTF-8) to four .po catalogs
  (asternicivr en_US/es_ES, backup pt_BR, admin amp pt_BR) so msgfmt compiles
  them instead of failing with "input file doesn't contain a header entry with
  a charset specification".
* Fri Oct 09 2026 Issabel Foundation <info@issabel.org> - 2.13.0-2
- Require the unversioned PHP packages (php-*, 8.3 on EL10) instead of the
  versioned php8.4-*, which conflict with the PHP 8.3 in the netinstall base.
