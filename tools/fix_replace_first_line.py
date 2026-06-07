#!/usr/bin/env python3
# Fix by replacing the broken first line and then fixing the rest
import os

def fix_replace_first_line(input_path, output_path):
    with open(input_path, 'rb') as f:
        raw = f.read()

    text_latin1 = raw.decode('latin-1')
    lines = text_latin1.splitlines(keepends=True)

    fixed_lines = []
    for line in lines:
        current_line = line
        for i in range(5):
            try:
                bytes_line = current_line.encode('latin-1')
                new_line = bytes_line.decode('utf-8')
                if new_line != current_line:
                    current_line = new_line
                else:
                    break
            except Exception:
                break
        fixed_lines.append(current_line)

    # Replace the first line entirely (it's just the phpMyAdmin header anyway!)
    first_line_correct = "-- phpMyAdmin SQL Dump\n"
    fixed_lines[0] = first_line_correct

    final_text = ''.join(fixed_lines)

    # Now try to apply the final fix, but handle the first part carefully!
    # Split into first line and the rest!
    first_line, rest = final_text.split('\n', 1)
    try:
        bytes_rest = rest.encode('latin-1')
        fixed_rest = bytes_rest.decode('utf-8')
        final_text = first_line + '\n' + fixed_rest
    except Exception as e:
        print(f"Final rest fix error: {e}")

    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(final_text)

    print("Fixed! Saved to:", output_path)
    print("\nFirst 15 lines preview:")
    print('\n'.join(final_text.splitlines()[:15]))

if __name__ == "__main__":
    input_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14).sql"
    output_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14)_fixed.sql"
    fix_replace_first_line(input_file, output_file)
