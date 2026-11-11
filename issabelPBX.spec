Name:           issabelPBX
Version:        2.13.0
Release:        29
Summary:        issabelPBX

License:        GPL
URL:            www.issabel.org
Source0:        issabelPBX-2.13.0.tar.gz
BuildArch:      noarch

BuildRequires:  bash php-cli
Requires(pre):  asterisk
Requires:       php-cli php-pdo php-mysqlnd php-mbstring mariadb perl-Digest-MD5 perl-URI perl-libwww-perl sox mpg123 perl-LWP-Protocol-https
Requires:       gettext
# The TTS AGI and module use the Piper runtime and its voice set.
Requires:       issabel-piper
Requires:       issabel-piper-voices
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

install -d %{buildroot}/etc/issabel
install -m 644 %{buildroot}/usr/src/issabelPBX/additionals/etc/issabel/piper.conf %{buildroot}/etc/issabel/piper.conf
install -d %{buildroot}/usr/libexec/issabel-tts
install -m 755 %{buildroot}/usr/src/issabelPBX/additionals/usr/libexec/issabel-tts/piper-slots %{buildroot}/usr/libexec/issabel-tts/piper-slots
install -d %{buildroot}/usr/bin
install -m 755 %{buildroot}/usr/src/issabelPBX/additionals/usr/bin/issabel-tts-voice %{buildroot}/usr/bin/issabel-tts-voice

%post

PODIR=/usr/src/issabelPBX

# Size the Piper concurrency pool from this machine. The script only replaces
# "slots = auto"; a numeric value set by the administrator is left untouched.
/usr/libexec/issabel-tts/piper-slots >/dev/null 2>&1 || :

# The historical picotts.agi is no longer shipped; remove a stale copy left by
# an older module install (retrieve_conf only copies, it never deletes).
rm -f /var/lib/asterisk/agi-bin/picotts.agi

killall -q -0 asterisk
if [ $? -eq 0 ]; then
echo "asterisk is up and running"
# EL10 runs MariaDB as mariadbd (no mysqld); accept either process name.
killall -q -0 mysqld || killall -q -0 mariadbd
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

# Resolve [voice] default_lang = auto from the system/GUI language. The CLI
# leaves a concrete value untouched, so an administrator choice survives.
/usr/bin/issabel-tts-voice default --from-gui >/dev/null 2>&1 || :

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
%config(noreplace) /etc/issabel/piper.conf
/usr/libexec/issabel-tts/piper-slots
/usr/bin/issabel-tts-voice

%doc

%changelog
* Wed Nov 11 2026 Issabel Foundation <info@issabel.org> - 2.13.0-29
- Make the IVR Text to Speech text field as wide as the select boxes and show
  five lines, so longer greetings are editable without scrolling.

* Sat Nov 07 2026 Issabel Foundation <info@issabel.org> - 2.13.0-28
- Add Text to Speech support to the IVR greeting. The prompt is generated as a
  cached audio file in custom/tts when the IVR is saved or the dialplan is
  applied, so Background/SpeechBackground and the retry announcements keep
  working without per-call synthesis.

* Fri Nov 06 2026 Issabel Foundation <info@issabel.org> - 2.13.0-27
- Make the Piper language selectable from the TTS, recordings and announcement
  GUIs without entering a .onnx file name. The shared voices.json catalog
  resolves language codes to model files; TTS entries can override the engine
  language through the existing tts_parameters column, and the calendar no
  longer keeps duplicate language maps. [voice] default_lang = auto follows the
  system/GUI language at runtime, while a concrete code pins it. Add
  issabel-tts-voice for list, test and default-language support tasks.

* Fri Nov 06 2026 Issabel Foundation <info@issabel.org> - 2.13.0-26
- Unify the TTS AGI under issabel-tts.agi and remove picotts.agi. One script
  now serves announcements, calendar reminders and recordings with a single
  contract (engine, base64 command and parameters, gui, interrupt keys, digit
  routing and speed), and the Piper voice map lives in one engine class
  shipped with the framework. Add a Piper concurrency pool sized from MemTotal
  and the CPU count at install time, a per-hash lock and atomic publication so
  two identical cache misses cannot synthesize twice or stream a partial file.

* Thu Nov 05 2026 Issabel Foundation <info@issabel.org> - 2.13.0-25
- Replace Pico TTS with Piper in picotts.agi, which keeps its historical name
  for announcement and calendar callfiles. The AGI selects one of the six
  packaged voices from the language prefix, caches by voice and speed, and uses
  absolute /usr/bin/piper and /usr/bin/sox paths. The TTS module drops the Pico
  engine, defaults to Piper, includes the voice in its cache key, and fixes the
  missing breaks in the cloud engines. Existing pico rows are migrated on
  module install. Add the Piper runtime and voice set as requirements.

* Fri Oct 30 2026 Issabel Foundation <info@issabel.org> - 2.13.0-24
- Align the tag, extension number and name of the right-hand navigation list
  into fixed columns, and ellipsize long names with a full-name tooltip.

* Fri Oct 30 2026 Issabel Foundation <info@issabel.org> - 2.13.0-23
- Add a "Migrate to WebRTC Device" / "Migrate to Generic Device" link to
  PJSIP extensions. It pre-fills the device form with the target values and
  the admin still has to review and submit it. The WebRTC preset and the
  migration share one helper, so they cannot diverge, and WebRTC devices also
  get direct_media=no now. WebRTC extensions are tagged WebRTC instead of
  pjsip in the navigation list. Translated to all shipped locales.

* Sat Oct 24 2026 Issabel Foundation <info@issabel.org> - 2.13.0-22
- Fix the device secret and AMPMGRPASS obfuscation: modern-bridge forces
  color !important on #page_body inputs, so the value was painted in normal
  text color. Hide it with -webkit-text-fill-color and a much heavier
  text-shadow.

* Thu Oct 22 2026 Issabel Foundation <info@issabel.org> - 2.13.0-21
- Ring Groups and VM Blast: fix a PHP 8 TypeError that aborted the add page
  when no group existed. '' == 0 is false since PHP 8, so the legacy fallback
  evaluated '' + 1; the suggested number now falls back to 600 (500) again.

* Fri Oct 16 2026 Issabel Foundation <info@issabel.org> - 2.13.0-20
- Fix the PBX admin navbar burger color in light mode: Bulma pins it white
  with .navbar.is-dark .navbar-burger (0,3,0), so match that selector.

* Fri Oct 16 2026 Issabel Foundation <info@issabel.org> - 2.13.0-19
- Use the theme surface for the PBX admin navbar (including its collapsed
  burger menu) and for section titles, instead of brand purple.

* Tue Oct 13 2026 Issabel Foundation <info@issabel.org> - 2.13.0-18
- Apply the Modern theme mode received from the framework tab shell.

* Tue Oct 13 2026 Issabel Foundation <info@issabel.org> - 2.13.0-17
- Read the requested element ID before FreePBX overwrites request variables.

* Tue Oct 13 2026 Issabel Foundation <info@issabel.org> - 2.13.0-16
- Preserve the requested extension ID in PBX tab metadata.

* Tue Oct 13 2026 Issabel Foundation <info@issabel.org> - 2.13.0-15
- Separate PBX list and element tab identities and report localized page metadata.

* Tue Oct 13 2026 Issabel Foundation <info@issabel.org> - 2.13.0-14
- Add the shared PBX tab bridge for page identity and deduplication.

* Tue Oct 13 2026 Issabel Foundation <info@issabel.org> - 2.13.0-13
- Modern bridge: theme bare content h5 section headers (.content h5), used by
  the System Recordings step headers, whose mainstyle #eee strip was unreadable
  in dark mode. Theme the Bulma file upload widget (.file-cta/.file-name), also
  used by backup restore and bulkdids.
* Tue Oct 13 2026 Issabel Foundation <info@issabel.org> - 2.13.0-12
- Modern bridge: theme the right drawer tab (#collapsemenuicon.menu_icon,
  created by pbxlib.js at runtime) with theme tokens instead of the fixed
  #eee/#000 from mainstyle.css, so it matches the action bar in dark mode.
  Drop the unused .xxxopenbtn rule.
* Sun Oct 11 2026 Issabel Foundation <info@issabel.org> - 2.13.0-11
- Add cache-busting (load_version + filectime) to the modern-bridge.css and
  fonts.css links, like mainstyle.css, so browsers pick up the updated files
  after an upgrade instead of serving a cached copy.

* Sat Oct 10 2026 Issabel Foundation <info@issabel.org> - 2.13.0-10
- Modern bridge: make FreePBX core forms readable in both themes. Fix page
  headings (mainstyle gray), the collapsible section h5 background, the native
  .componentSelect, and the action-bar buttons (Submit/Reset/Delete/Back) with
  the brand palette. Paint the active module dropdown item with a soft brand
  wash instead of mainstyle's #4b0884.
- Modern bridge: theme the Chosen widgets (control, drop panel and the blue
  #428bca highlight), the right drawer (#rnavadd, current item, open tab) and
  the native form color-scheme. Use the official brand purple (#572364).

* Sat Oct 10 2026 Issabel Foundation <info@issabel.org> - 2.13.0-9
- %post: detect MariaDB via mariadbd (EL10 no longer runs mysqld), so install_amp
  no longer skips and refreshes /var/www/html/admin. Drop the speculative
  dark-shim "hardening" that chased a deployment artifact.
- Modern bridge: give the navbar a min-height and accent the brand icon; style
  the dashboard System Statistics (.infobox/.databox bars) for both themes.

* Sat Oct 10 2026 Issabel Foundation <info@issabel.org> - 2.13.0-8
- Allow .woff2 (and .otf) in admin/.htaccess: webfonts were returning 403, so
  Inter and Font Awesome failed to load. Harden the dark shim over mainstyle.css
  (h5/.h5, .ui-dialog, .table.is-borderless, dashboard widgets, links).

* Sat Oct 10 2026 Issabel Foundation <info@issabel.org> - 2.13.0-7
- Bridge: give Bulma buttons a subtle default surface and force white text on the
  primary/danger/success/info variants (readable in light and dark).

* Sat Oct 10 2026 Issabel Foundation <info@issabel.org> - 2.13.0-6
- Modern bridge: brand the FreePBX chrome (Bulma navbar is-dark -> Issabel purple)
  and add a dark shim over mainstyle.css so surfaces and text stay readable in dark.

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
