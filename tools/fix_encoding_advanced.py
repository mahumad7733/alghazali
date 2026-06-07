#!/usr/bin/env python3
# Advanced encoding fix for Arabic text
import os

def fix_file(input_path, output_path):
    # Read raw bytes
    with open(input_path, 'rb') as f:
        data = f.read()
    
    # Remove BOM if present
    if data.startswith(b'\xef\xbb\xbf'):
        data = data[3:]
    
    # Try multiple approaches
    text = None
    
    # Approach 1: Try double Latin-1 → UTF-8 (common case for double-encoded)
    try:
        step1 = data.decode('latin-1')
        step2 = step1.encode('latin-1')
        text = step2.decode('utf-8')
        
        # Try another round if needed
        try:
            step3 = text.encode('latin-1')
            text = step3.decode('utf-8')
        except:
            pass
            
    except Exception as e:
        print(f"Approach 1 failed: {e}")
    
    # If approach 1 failed, try Windows-1252
    if not text:
        try:
            step1 = data.decode('windows-1252')
            step2 = step1.encode('windows-1252')
            text = step2.decode('utf-8')
        except Exception as e:
            print(f"Approach 2 failed: {e}")
            text = data.decode('utf-8', errors='replace')
    
    # Write the result with UTF-8
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(text)
    
    print(f"✓ Fixed file written to {output_path}")

if __name__ == "__main__":
    input_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14).sql"
    output_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14)_fixed.sql"
    fix_file(input_file, output_file)
