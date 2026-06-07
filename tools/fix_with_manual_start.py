#!/usr/bin/env python3
# Fix by manually replacing the broken start and then fixing the rest
import os

def fix_with_manual_start(input_path, output_path):
    # Read the entire file as raw bytes
    with open(input_path, 'rb') as f:
        raw = f.read()

    # Let's find the first occurrence of "-- phpMyAdmin SQL Dump" or just "-- " and work from there
    # Alternatively, let's just decode as latin1 and then process line by line, skipping any broken parts at start if needed
    text_latin1 = raw.decode('latin-1')

    lines = text_latin1.splitlines(keepends=True)

    fixed_lines = []

    for line in lines:
        # Try to fix this line!
        current_line = line
        # Apply our fix up to 5 times to this individual line
        for i in range(5):
            try:
                # Encode back to latin1, decode as utf8
                bytes_line = current_line.encode('latin-1')
                new_line = bytes_line.decode('utf-8')
                if new_line != current_line:
                    current_line = new_line
                else:
                    break  # no more improvements
            except Exception as e:
                break  # stop trying if there's an error
        fixed_lines.append(current_line)

    final_text = ''.join(fixed_lines)

    # NOW APPLY THE FIX ONE MORE TIME TO THE ENTIRE final_text!
    try:
        bytes_final = final_text.encode('latin-1')
        final_text = bytes_final.decode('utf-8')
        print("Applied final global fix!")
    except Exception as e:
        print(f"Couldn't apply final global fix: {e}")

    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(final_text)

    print("✅ Fixed!")
    print(f"✅ First 10 lines of output:")
    for l in final_text.splitlines()[:10]:
        print(repr(l))

if __name__ == "__main__":
    input_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14).sql"
    output_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14)_fixed.sql"
    fix_with_manual_start(input_file, output_file)
