#!/usr/bin/env python3
# Script to fix double (or multiple) encoding of Arabic text
import os

def fix_encoding(input_file, output_file=None):
    if output_file is None:
        base, ext = os.path.splitext(input_file)
        output_file = f"{base}_fixed{ext}"

    # Read the corrupted file as raw bytes
    with open(input_file, 'rb') as f:
        raw = f.read()

    # Try to fix multiple times
    content = raw
    for _ in range(3):  # Try up to 3 rounds of fixing
        try:
            # Common case: UTF-8 bytes were interpreted as Latin-1
            step1 = content.decode('latin-1')
            step2 = step1.encode('latin-1')
            content = step2.decode('utf-8')
        except:
            break

    # Write the fixed content as UTF-8
    with open(output_file, 'w', encoding='utf-8-sig') as f:
        f.write(content)
    
    print(f"✓ Fixed file saved to {output_file}")

if __name__ == "__main__":
    input_file = r'c:\xampp\htdocs\ghazali\tools\ghazali (14).sql'
    fix_encoding(input_file)
