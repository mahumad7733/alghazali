
with open(r"c:\xampp\htdocs\ghazali\tools\ghazali (14)_fixed.sql", "r", encoding="utf-8") as f:
    lines = f.readlines()

with open(r"c:\xampp\htdocs\ghazali\tools\test_sample.sql", "w", encoding="utf-8") as f:
    for line in lines[40:50]:  # Lines 40-50 are the stored procedure Arabic comments
        f.write(line)
