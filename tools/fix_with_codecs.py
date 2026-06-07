#!/usr/bin/env python3
# Fix using codecs module to handle multi-step encoding, after stripping BOM
import os
import codecs

def fix_mojibake(input_path, output_path):
    with open(input_path, 'rb') as f:
        raw_data = f.read()

    # Strip BOM first, as raw bytes!
    if raw_data.startswith(b'\xef\xbb\xbf'):  # UTF-8 BOM
        raw_data = raw_data[3:]
        print("Removed UTF-8 BOM")

    # Now decode the raw bytes as latin-1 (so we can start fixing!)
    text = raw_data.decode('latin-1')

    # Now apply our fix rounds!
    for i in range(5):  # try up to 5 rounds
        try:
            # Try to encode as latin-1 (which maps each byte 0-255 directly to U+0000 to U+00FF)
            bytes_data = text.encode('latin-1')
            # Now decode those bytes as utf-8
            new_text = bytes_data.decode('utf-8')
            # If we made progress (less garbled), keep it
            if new_text != text:
                print(f"Round {i+1}: improved!")
                text = new_text
            else:
                break
        except Exception as e:
            print(f"Round {i+1}: stopped at {e}")
            break

    # Save the final result as UTF-8
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(text)

    print(f"✅ Done! Saved to {output_path}")

if __name__ == "__main__":
    input_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14).sql"
    output_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14)_fixed.sql"
    fix_mojibake(input_file, output_file)
