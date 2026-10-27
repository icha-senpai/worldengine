#!/usr/bin/env python3
"""Install HTTPS routing for the two apps; app services remain user-owned."""
import argparse
import os
from pathlib import Path
import shutil
import subprocess
from hosting_security import HEADER as SECURITY_HEADER, DIRECTORY, MAP_PATH, DOMAINS as SECURITY_DOMAINS, headers, http_config, patch_site

HEADER = "# Managed Dataverse Linux hosting.\n"
DOMAINS = ["bitcraft.ichaa.dev", "fishbound.ichaa.dev"]


def run(*args):
    subprocess.run(args, check=True)


def managed_file(path, text, marker=HEADER):
    path = Path(path)
    if path.is_symlink():
        raise RuntimeError(f"Linked configuration refused: {path}")
    if path.exists() and not path.read_text().startswith(marker):
        raise RuntimeError(f"Unmanaged configuration preserved: {path}")
    stage = path.with_suffix(path.suffix + ".new")
    if stage.exists():
        raise RuntimeError(f"Unfinished configuration stage: {stage}")
    with stage.open("x") as file:
        file.write(text if text.startswith(marker) else marker + text)
    stage.chmod(0o644)
    stage.replace(path)


def http_site(redirect):
    destination = "return 301 https://$host$request_uri;" if redirect else "return 503;"
    return """server {
    listen 80;
    listen [::]:80;
    server_name bitcraft.ichaa.dev fishbound.ichaa.dev;
    location ^~ /.well-known/acme-challenge/ {
        root /var/lib/letsencrypt;
        try_files $uri =404;
    }
    location / { %s }
}
""" % destination


def proxy(port, websocket=False, public_host=None):
    headers = f"""        proxy_pass http://127.0.0.1:{port};
        proxy_http_version 1.1;
        proxy_set_header Host {public_host or '$host'};
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_buffering off;
"""
    if websocket:
        headers += """        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $dataverse_upgrade;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
"""
    return headers


def https_site(domain, body):
    return """server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name %s;
    ssl_certificate /etc/letsencrypt/live/dataverse-apps/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/dataverse-apps/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    if (-f /var/www/dataverse/.maintenance) { return 503; }
%s
}
""" % (domain, body)


def main():
    import pwd
    parser = argparse.ArgumentParser()
    parser.add_argument("--user", default="icha")
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise SystemExit("Run the reviewed host setup with sudo.")
    account = pwd.getpwnam(args.user)
    public = Path("/var/www/dataverse")
    if public.is_symlink():
        raise RuntimeError("Linked public directory refused.")
    public.mkdir(mode=0o755, exist_ok=True)
    os.chown(public, account.pw_uid, account.pw_gid)
    public.chmod(0o755)
    maintenance = public / ".maintenance"
    if maintenance.is_symlink():
        raise RuntimeError("Linked maintenance flag refused.")
    maintenance.touch(exist_ok=True)
    os.chown(maintenance, account.pw_uid, account.pw_gid)
    Path("/var/lib/letsencrypt").mkdir(mode=0o755, exist_ok=True)
    if not shutil.which("certbot"):
        run("apt-get", "update")
        run("apt-get", "install", "-y", "certbot")
    managed_file("/etc/nginx/conf.d/dataverse-upgrade.conf", """map $http_upgrade $dataverse_upgrade {
    default upgrade;
    '' close;
}
""")
    site = Path("/etc/nginx/sites-available/dataverse-apps")
    enabled = Path("/etc/nginx/sites-enabled/dataverse-apps")
    if enabled.exists() or enabled.is_symlink():
        if not enabled.is_symlink() or enabled.resolve() != site:
            raise RuntimeError("Existing unrelated enabled site preserved.")
    managed_file(site, http_site(False))
    if not enabled.is_symlink():
        enabled.symlink_to(site)
    run("nginx", "-t")
    run("systemctl", "reload", "nginx")
    if shutil.which("ufw"):
        status = subprocess.check_output(["ufw", "status"], text=True)
        if "Status: active" in status:
            run("ufw", "allow", "80/tcp")
            run("ufw", "allow", "443/tcp")
    certificate = ["certbot", "certonly", "--webroot", "-w", "/var/lib/letsencrypt",
                   "--cert-name", "dataverse-apps", "--non-interactive", "--agree-tos",
                   "--register-unsafely-without-email", "--keep-until-expiring"]
    for domain in DOMAINS:
        certificate += ["-d", domain]
    run(*certificate)
    bitcraft = """    root /var/www/dataverse/bitcraft;
    index index.html;
    location ~ ^/v1/database/(space-bitcraft-tools|space-evergather)/subscribe$ {
        access_log off;
""" + proxy(3100, True) + """    }
    location = /v1/identity/websocket-token {
        access_log off;
""" + proxy(3100) + """    }
    location /v1/ { return 404; }
    location = /index.html { add_header Cache-Control "no-cache"; }
    location /assets/ { try_files $uri =404; }
    location ~ /\\. { return 404; }
    location / { try_files $uri $uri/ /index.html; }
"""
    fish = """    location = /v1/database/fishbound-dev-local/subscribe {
        access_log off;
""" + proxy(3127, True) + """    }
    location = /v1/identity/websocket-token {
        access_log off;
""" + proxy(3127) + """    }
    location ^~ /v1/ { return 404; }
    location ^~ /auth/discord/ {
        access_log off;
""" + proxy(3001, public_host="fishbound.ichaa.dev") + """    }
    location = /health/account-link {
        proxy_pass http://127.0.0.1:3001/health/ready;
    }
    location / {
""" + proxy(5173) + """    }
"""
    managed_file(MAP_PATH, http_config(), SECURITY_HEADER)
    Path(DIRECTORY).mkdir(parents=True, exist_ok=True)
    for domain in SECURITY_DOMAINS:
        managed_file(Path(DIRECTORY) / f'dataverse-{domain}-security.conf', headers(domain), SECURITY_HEADER)
    managed_file(site, patch_site(HEADER + http_site(True) + https_site(DOMAINS[0], bitcraft) + https_site(DOMAINS[1], fish)))
    hook = Path("/etc/letsencrypt/renewal-hooks/deploy/dataverse-nginx")
    hook.parent.mkdir(parents=True, exist_ok=True)
    if hook.exists() and "Dataverse" not in hook.read_text():
        raise RuntimeError("Unrelated certificate hook preserved.")
    hook.write_text("#!/bin/sh\n# Dataverse certificate reload\n/usr/sbin/nginx -t && /usr/bin/systemctl reload nginx\n")
    hook.chmod(0o755)
    run("nginx", "-t")
    run("systemctl", "reload", "nginx")
    run("loginctl", "enable-linger", args.user)
    print("HTTPS routing is ready. Maintenance remains enabled until data and services are verified.")


if __name__ == "__main__":
    main()
