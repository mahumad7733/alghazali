#!/usr/bin/env python3
# Apply the fix until there are no more 'Ã' characters
import os

def fix_until_right(input_path, output_path):
    with open(input_path, 'rb') as f:
        data = f.read()

    # Strip BOM
    if data.startswith(b'\xef\xbb\xbf'):
        data = data[3:]

    text = data.decode('latin-1')

    while 'Ã' in text:
        try:
            text_bytes = text.encode('latin-1')
            text = text_bytes.decode('utf-8')
        except Exception as e:
            print(f"Stopping fix early: {e}")
            break

    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(text)

    print("✅ Fix complete!")
    print(f"✅ File saved to {output_path}")

if __name__ == "__main__":
    input_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14).sql"
    output_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14)_fixed.sql"
    fix_until_right(input_file, output_file)
