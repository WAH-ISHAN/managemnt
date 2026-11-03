"""Create a private local CA and HTTPS certificate for one explicit LAN address.

Requires: python -m pip install cryptography
Run with --listen 192.168.1.10 --output PATH. Install only phone-ca.cer on
your own phone as a trusted CA; keep all .key files on the desktop. No trust
stores or firewall rules are changed by this command.
"""
import argparse
import csv
from datetime import datetime, timedelta, timezone
import hashlib
import io
import ipaddress
import os
from pathlib import Path
import subprocess

def create(address, output):
    from cryptography import x509
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import rsa
    from cryptography.x509.oid import NameOID, ExtendedKeyUsageOID
    address = ipaddress.ip_address(address)
    if address.version != 4 or not address.is_private or address.is_unspecified:
        raise ValueError('Choose a specific private IPv4 address.')
    output = Path(output)
    output.mkdir(mode=0o700, parents=True, exist_ok=True)
    if any(output.iterdir()):
        raise ValueError('Choose an empty certificate directory. Existing identities are never replaced.')
    if os.name == 'nt':
        result = subprocess.run(['whoami', '/user', '/fo', 'csv', '/nh'], check=True, capture_output=True, text=True)
        sid = next(csv.reader(io.StringIO(result.stdout)))[1]
        subprocess.run(['icacls', str(output), '/inheritance:r', '/grant:r', f'*{sid}:(OI)(CI)F', '*S-1-5-18:(OI)(CI)F'], check=True, capture_output=True)
    now = datetime.now(timezone.utc)
    ca_key = rsa.generate_private_key(public_exponent=65537, key_size=3072)
    ca_name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, 'LifeOS private phone CA')])
    ca = (x509.CertificateBuilder().subject_name(ca_name).issuer_name(ca_name)
          .public_key(ca_key.public_key()).serial_number(x509.random_serial_number())
          .not_valid_before(now-timedelta(minutes=5)).not_valid_after(now+timedelta(days=365))
          .add_extension(x509.BasicConstraints(ca=True, path_length=0), critical=True)
          .add_extension(x509.KeyUsage(False, False, False, False, False, True, True, False, False), critical=True)
          .sign(ca_key, hashes.SHA256()))
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    cert = (x509.CertificateBuilder()
            .subject_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, 'LifeOS phone companion')]))
            .issuer_name(ca_name).public_key(key.public_key()).serial_number(x509.random_serial_number())
            .not_valid_before(now-timedelta(minutes=5)).not_valid_after(now+timedelta(days=90))
            .add_extension(x509.SubjectAlternativeName([x509.IPAddress(address)]), critical=False)
            .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
            .add_extension(x509.ExtendedKeyUsage([ExtendedKeyUsageOID.SERVER_AUTH]), critical=False)
            .sign(ca_key, hashes.SHA256()))
    for name, data in {
        'phone-ca.cer': ca.public_bytes(serialization.Encoding.DER),
        'phone-ca.pem': ca.public_bytes(serialization.Encoding.PEM),
        'desktop-ca.key': ca_key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()),
        'desktop-server.key': key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()),
        'desktop-server.pem': cert.public_bytes(serialization.Encoding.PEM),
    }.items():
        with (output/name).open('xb') as handle:
            handle.write(data)
        if name.endswith('.key'):
            os.chmod(output/name, 0o600)
    return hashlib.sha256(ca.public_bytes(serialization.Encoding.DER)).hexdigest()

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--listen', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    fingerprint = create(args.listen, args.output)
    print(f'CA SHA-256: {fingerprint}\nPhone certificate: {args.output / "phone-ca.cer"}')
    print('Verify this fingerprint before trusting the CA on your phone. Server certificate expires in 90 days.')
    print('Run mobile_companion.py using desktop-server.pem and desktop-server.key. Use a new empty directory to rotate certificates.')

if __name__ == '__main__':
    main()
