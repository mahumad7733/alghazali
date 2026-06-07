#!/usr/bin/env python3
# Final fix: apply exactly 2 rounds of Latin-1 → UTF-8 encoding fix, after stripping BOM
import os

def double_fix(input_path, output_path):
    with open(input_path, 'rb') as f:
        data = f.read()
    
    # Strip BOM if present
    if data.startswith(b'\xef\xbb\xbf'):
        data = data[3:]
    
    # Round 1
    text = data.decode('latin-1').encode('latin-1').decode('utf-8')
    # Round 2 - try, if it fails, stop
    try:
        text = text.encode('latin-1').decode('utf-8')
    except:
        pass
    
    # Write the result as UTF-8
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(text)
    
    print("✓ Fix applied!")
    print(f"✓ File saved to {output_path}")

if __name__ == "__main__":
    input_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14).sql"
    output_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14)_fixed.sql"
    double_fix(input_file, output_file)
