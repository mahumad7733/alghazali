
with open(r"c:\xampp\htdocs\ghazali\tools\ghazali (14)_fixed.sql", "r", encoding="utf-8") as f:
    for i, line in enumerate(f):
        if i > 200:
            break
        print(line, end="")
