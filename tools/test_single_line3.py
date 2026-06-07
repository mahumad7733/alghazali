
problem_line = "-- Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â¶Ãƒâ„¢Ã…Â Ãƒâ„¢Ã‚Â: 127.0.0.1"

# Step 1: Replace any characters > 0xFF with appropriate things!
cleaned = []
for c in problem_line:
    o = ord(c)
    if o <= 0xFF:
        cleaned.append(c)
    else:
        # Handle known problematic characters!
        if o == 0x0192:  # this is the one causing issues!
            cleaned.append(chr(0x83))
        elif o == 0x2026:
            cleaned.append(chr(0x85))
        elif o == 0x2018:
            cleaned.append(chr(0x91))
        elif o == 0x2019:
            cleaned.append(chr(0x92))
        elif o == 0x201C:
            cleaned.append(chr(0x93))
        elif o == 0x201D:
            cleaned.append(chr(0x94))
        elif o == 0x2022:
            cleaned.append(chr(0x95))
        elif o == 0x2013:
            cleaned.append(chr(0x96))
        elif o == 0x2014:
            cleaned.append(chr(0x97))
        elif o == 0x2122:
            cleaned.append(chr(0x99))
        else:
            # Just skip it or replace with '?'
            cleaned.append('?')

cleaned_line = ''.join(cleaned)

# Now apply our fix to cleaned_line!
b1 = cleaned_line.encode('latin-1')
t1 = b1.decode('utf-8')

b2 = t1.encode('latin-1')
t2 = b2.decode('utf-8')

with open(r"c:\xampp\htdocs\ghazali\tools\test_line_fixed.txt", "w", encoding='utf-8') as f:
    f.write(t2)
