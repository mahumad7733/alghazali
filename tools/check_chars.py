
with open(r"c:\xampp\htdocs\ghazali\tools\ghazali (14)_fixed.sql", "r", encoding="utf-8") as f:
    lines = f.readlines()

# Line 6 is one with Arabic text
print("Line 6 raw:", repr(lines[5]))
print("\nFirst 20 characters:", [repr(c) for c in lines[5][:20]])

# Let's also write a simple file with just Arabic text to test if Read tool shows it correctly
with open(r"c:\xampp\htdocs\ghazali\tools\just_arabic.txt", "w", encoding="utf-8") as f:
    f.write("هذا نص عربي للتجربة\n")
    f.write("السنة المالية لم يتم العثور عليها\n")
