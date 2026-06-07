#!/usr/bin/env python3
# Final fix: take the file we have and apply the fix to parts that need it
import os

def final_fix(input_path, output_path):
    # Read our current fixed file
    with open(input_path, 'r', encoding='utf-8') as f:
        current_text = f.read()

    # Let's write a helper function to try to fix a string
    def try_fix(s):
        # First, try to encode as much as possible as latin-1
        # We'll split the string into parts: sequences of chars <= U+00FF (which can be encoded as latin-1)
        # and other chars, and try to fix the latin-1 sequences!
        result = []
        i = 0
        n = len(s)
        while i < n:
            # Find a run of characters that are all <= U+00FF (i.e., can be encoded as latin-1)
            start = i
            while i < n and ord(s[i]) <= 0xFF:
                i += 1
            if start < i:
                # This is a run we can try to fix!
                run = s[start:i]
                try:
                    bytes_run = run.encode('latin-1')
                    fixed_run = bytes_run.decode('utf-8')
                    result.append(fixed_run)
                except:
                    # If we can't fix this run, just leave it as is
                    result.append(run)
            else:
                # Single character > U+00FF
                result.append(s[i])
                i += 1
        return ''.join(result)

    # Apply our try_fix() function to the entire current_text
    final_text = try_fix(current_text)

    # Now save it!
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(final_text)

    print("All done!")
    print("\nFirst 20 lines of final output:")
    print("-" * 50)
    for line in final_text.splitlines()[:20]:
        print(line)
    print("-" * 50)

if __name__ == "__main__":
    input_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14)_fixed.sql"
    output_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14)_fixed.sql"
    final_fix(input_file, output_file)
