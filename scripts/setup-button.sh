#!/bin/sh
# Connects the button on the scanner to Scan to PDF through scanbd. Run once as root.
set -eu

conf=/etc/scanbd/scanbd.conf
[ "$(id -u)" = 0 ] || { echo "Please run this with sudo."; exit 1; }
if [ ! -f "$conf" ]; then
    command -v apt-get >/dev/null || { echo "Please install scanbd with your package manager and run this again."; exit 1; }
    DEBIAN_FRONTEND=noninteractive apt-get install -y scanbd
fi

app=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
root=$(CDPATH= cd -- "$app/../.." && pwd)
occ="$root/occ"
[ -f "$occ" ] || { echo "Could not find occ at $occ"; exit 1; }
php=$(command -v php) || { echo "php command line program not found"; exit 1; }
user=$(stat -c %U "$occ")
pkill=$(command -v pkill) || { echo "pkill not found, please install procps"; exit 1; }
command -v sudo >/dev/null || { echo "sudo not found, please install it"; exit 1; }

# Drivers name their buttons differently, so every action that reacts to a button starts the scan
connect_buttons() {
    [ -f "$1.orig" ] || cp "$1" "$1.orig"
    awk '
        /^[[:space:]]*action[[:space:]]/ { button = 0 }
        /^[[:space:]]*filter[[:space:]]*=/ { button = /scan|email|copy|file|button|send|web/ && !/message|function/ }
        button && /^[[:space:]]*script[[:space:]]*=/ { sub(/=.*/, "= \"scantopdf.script\"") }
        { print }
    ' "$1" > "$1.new"
    cat "$1.new" > "$1"
    rm -f "$1.new"
}

connect_buttons "$conf"
for file in /etc/scanbd/scanner.d/*.conf; do
    [ -f "$file" ] && connect_buttons "$file"
done
sed -i -E \
    -e "s/^([[:space:]]*user[[:space:]]*=).*/\1 $user/" \
    -e 's/filter = "\^scan\.\*"/filter = "^(scan|file|button).*"/' \
    "$conf"

mkdir -p /etc/scanbd/scripts
# scanbd drops the supplementary groups of the user, sudo restores them. Nextcloud needs them, for example to reach Redis.
cat > /etc/scanbd/scripts/scantopdf.script <<SCRIPT
#!/bin/sh
exec sudo -n -u "$user" "$php" "$occ" scantopdf:button
SCRIPT
chmod 755 /etc/scanbd/scripts/scantopdf.script

mkdir -p /etc/systemd/system/scanbd.service.d
cat > /etc/systemd/system/scanbd.service.d/scantopdf.conf <<UNIT
[Service]
Environment=SANE_CONFIG_DIR=/etc/sane.d
UNIT

# Without this group the web server cannot open scanners on a normal host
getent group scanner >/dev/null && usermod -aG scanner "$user"

# scanbd may only register on the system bus as the user it runs as
if [ -f /etc/dbus-1/system.d/scanbd_dbus.conf ]; then
    sed -i "s/<policy user=\"[^\"]*\">/<policy user=\"$user\">/" /etc/dbus-1/system.d/scanbd_dbus.conf
    systemctl reload dbus 2>/dev/null || true
fi

# Scans started from Nextcloud have to make scanbd let go of the scanner, the button script needs its groups back.
# Only these three commands are allowed.
sudoers=$(mktemp)
echo "$user ALL=(root) NOPASSWD: $pkill -USR1 -x scanbd, $pkill -USR2 -x scanbd" > "$sudoers"
echo "$user ALL=($user) NOPASSWD: $php $occ scantopdf\\:button" >> "$sudoers"
visudo -cf "$sudoers" >/dev/null || { rm -f "$sudoers"; echo "Could not create the sudo rule"; exit 1; }
install -m 440 "$sudoers" /etc/sudoers.d/scantopdf
rm -f "$sudoers"

# The network frontend of scanbd is not needed for the button
systemctl disable --now scanbm.socket 2>/dev/null || true

# Containers get no udev events, so scanbd would not notice a scanner that comes back
if systemd-detect-virt --container --quiet; then
    cat > /usr/local/sbin/scantopdf-usbwatch <<'WATCH'
#!/bin/sh
last=$(ls /dev/bus/usb/*/* 2>/dev/null)
while sleep 2; do
    now=$(ls /dev/bus/usb/*/* 2>/dev/null)
    if [ "$now" != "$last" ]; then
        sleep 3
        systemctl restart scanbd
        now=$(ls /dev/bus/usb/*/* 2>/dev/null)
    fi
    last=$now
done
WATCH
    chmod 755 /usr/local/sbin/scantopdf-usbwatch
    cat > /etc/systemd/system/scantopdf-usbwatch.service <<UNIT
[Unit]
Description=Restart scanbd when a USB device appears or disappears

[Service]
ExecStart=/usr/local/sbin/scantopdf-usbwatch
Restart=always

[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload
    systemctl enable --now scantopdf-usbwatch.service
fi

systemctl daemon-reload
systemctl enable scanbd
systemctl restart scanbd
echo "Done. scanbd runs as $user and starts Scan to PDF when the scan button is pressed."
