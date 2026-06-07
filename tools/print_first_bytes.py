
with open(r"c:\xampp\htdocs\ghazali\tools\ghazali (14).sql", "rb") as f:
    raw_bytes = f.read(100)

print("First 100 raw bytes (hex):")
print(" ".join(f"{b:02x}" for b in raw_bytes))
print("\nFirst 100 bytes decoded as latin1:")
print(repr(raw_bytes.decode('latin-1')))
print("\nFirst 100 bytes decoded as utf8:")
try:
    print(repr(raw_bytes.decode('utf-8')))
except Exception as e:
    print(f"Couldn't decode as utf8: {e}")
