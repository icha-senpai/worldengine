"""Security headers for the two managed HTTPS sites."""
import re

HEADER = '# Managed Dataverse security headers.\n'
DIRECTORY = '/etc/nginx/snippets'
MAP_PATH = '/etc/nginx/conf.d/dataverse-security.conf'
DOMAINS = ('bitcraft.ichaa.dev', 'fishbound.ichaa.dev')


def http_config():
    # Use the original path: SPA fallback changes $uri to /index.html.
    return HEADER + '''map $request_uri $dataverse_frame_ancestors {
    default "'none'";
    ~^/bitcraft/(activity|inventory|passive-crafts|tasks)/widget/?(?:\\?|$) "*";
}
map $dataverse_frame_ancestors $dataverse_frame_options {
    default DENY;
    "*" "";
}
'''


def headers(domain):
    if domain not in DOMAINS:
        raise ValueError('Unknown security-header site')
    # Fishbound supplies an additional nonce-based script policy in SvelteKit.
    # Space has no inline scripts. Rich text needs inline styles and HTTPS images.
    scripts = "'self' 'unsafe-inline'" if domain == DOMAINS[1] else "'self'"
    ancestors = '$dataverse_frame_ancestors' if domain == DOMAINS[0] else "'none'"
    framing = '$dataverse_frame_options' if domain == DOMAINS[0] else 'DENY'
    csp = (f"default-src 'self'; script-src {scripts}; style-src 'self' 'unsafe-inline'; "
           "img-src 'self' https: data: blob:; font-src 'self' https: data:; "
           "connect-src 'self' https: wss:; worker-src 'self' blob:; "
           "object-src 'none'; base-uri 'self'; frame-src 'none'; "
           "form-action 'self' https://fishbound.ichaa.dev https://discord.com; "
           f"frame-ancestors {ancestors}")
    return HEADER + f'''add_header Strict-Transport-Security "max-age=31536000" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "{framing}" always;
add_header Referrer-Policy "no-referrer" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
add_header Content-Security-Policy "{csp}" always;
'''


def include(domain):
    return f'include {DIRECTORY}/dataverse-{domain}-security.conf;'


def patch_site(text):
    if not text.startswith('# Managed Dataverse Linux hosting.\n'):
        raise RuntimeError('Unmanaged NGINX site preserved')
    found = []

    def patch(match):
        block = match[0]
        domain = next((d for d in DOMAINS if f'server_name {d};' in block), None)
        if domain is None or 'listen 443 ssl;' not in block:
            return block
        found.append(domain)
        directive = include(domain)
        block = re.sub(r'^[ \t]*include /etc/nginx/snippets/dataverse-[^;]+-security.conf;\n', '', block, flags=re.M)
        block = block.replace('server {\n', f'server {{\n    {directive}\n', 1)
        # A location with its own add_header loses all inherited server headers
        # on NGINX 1.28. Include here too, including diagnostic/error locations.
        block = re.sub(r'^([ \t]*location [^\n{]+\{)\n?', lambda m: m[1] + f'\n        {directive}\n', block, flags=re.M)
        # Never trust a client-supplied forwarding header for login rate limits.
        block = re.sub(r'[ \t]*proxy_set_header X-Real-IP [^;]+;\n?', '', block)
        block = re.sub(r'proxy_set_header X-Forwarded-Proto https;[ \t]*\n?', 'proxy_set_header X-Forwarded-Proto https;\n        proxy_set_header X-Real-IP $remote_addr;\n', block)
        return block

    result = re.sub(r'^server \{\n.*?^}\n', patch, text, flags=re.M | re.S)
    if sorted(found) != sorted(DOMAINS):
        raise RuntimeError('Expected exactly the two managed HTTPS sites')
    return result
