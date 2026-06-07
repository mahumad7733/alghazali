
problem_line = "-- Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â¶Ãƒâ„¢Ã…Â\xa0Ãƒâ„¢Ã‚Â: 127.0.0.1"

print("Original problematic line:", repr(problem_line))
print("Characters with their ordinals:")
for i, c in enumerate(problem_line):
    print(i, repr(c), ord(c))

# Step 1: Replace any characters > 0xFF with '?'
cleaned = []
for c in problem_line:
    if ord(c) <= 0xFF:
        cleaned.append(c)
    else:
        # Let's just skip it? Or replace with something? Wait let's see what ord(c) is here at i=4: 0x192
        # Let's replace '\u0192' with '\x83'? Because the original triple-encoded bytes probably had 0x83 there!
        if ord(c) == 0x0192:
            cleaned.append(chr(0x83))
        else:
            cleaned.append('?')

cleaned_line = ''.join(cleaned)

print("\nCleaned line:", repr(cleaned_line))

# Now apply our fix to cleaned_line!
b1 = cleaned_line.encode('latin-1')
print("Encoded cleaned line as latin-1 bytes:", b1)
t1 = b1.decode('utf-8')
print("t1 (fixed once):", repr(t1))

b2 = t1.encode('latin-1')
t2 = b2.decode('utf-8')
print("\nFinal fixed line t2:", repr(t2))
print("Final fixed line as text:", t2)
