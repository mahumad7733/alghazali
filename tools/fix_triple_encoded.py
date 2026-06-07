#!/usr/bin/env python3
# Fix triple encoded UTF-8 file
import os

def fix_triple_encoded(input_path, output_path):
    with open(input_path, 'rb') as f:
        raw_bytes = f.read()

    print("Original raw bytes len:", len(raw_bytes))

    # Step 1: First undo first encoding step
    step1 = raw_bytes.decode('latin-1').encode('latin-1').decode('utf-8')
    print("After step 1 (first decode): len=", len(step1))

    # Step 2: Undo second encoding step
    step2 = step1.encode('latin-1').decode('utf-8')
    print("After step 2 (second decode): len=", len(step2))

    # Step 3: Undo third encoding step (if possible)
    try:
        step3 = step2.encode('latin-1').decode('utf-8')
        final = step3
        print("Applied step3!")
    except:
        final = step2
        print("Step3 not needed")

    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(final)

    print(f"✅ Saved fixed file to: {output_path}")
    print(f"✅ First 200 chars of fixed text: {repr(final[:200])}")

if __name__ == "__main__":
    input_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14).sql"
    output_file = r"c:\xampp\htdocs\ghazali\tools\ghazali (14)_fixed.sql"
    fix_triple_encoded(input_file, output_file)
