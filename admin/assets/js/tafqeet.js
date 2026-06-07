/**
 * Tafqeet JS - تحويل الأرقام إلى كلمات باللغة العربية
 * يمكن استخدامه في كافة صفحات النظام لتقديم تغذية راجعة فورية للمستخدم
 */
function tafqeet(n, currencyName = "ريال") {
    if (n === "" || isNaN(n) || n == 0) return "";
    
    const ones = ["", "واحد", "اثنان", "ثلاثة", "أربعة", "خمسة", "ستة", "سبعة", "ثمانية", "تسعة", "عشرة", "أحد عشر", "اثنا عشر", "ثلاثة عشر", "أربعة عشر", "خمسة عشر", "ستة عشر", "سبعة عشر", "ثمانية عشر", "تسعة عشر"];
    const tens = ["", "", "عشرون", "ثلاثون", "أربعون", "خمسون", "ستون", "سبعون", "ثمانون", "تسعون"];
    const hundreds = ["", "مائة", "مائتان", "ثلاثمائة", "أربعمائة", "خمسمائة", "ستمائة", "سبعمائة", "ثمانمائة", "تسعمائة"];

    function convertPart(num) {
        let partStr = "";
        const h = Math.floor(num / 100);
        const t = Math.floor((num % 100) / 10);
        const o = num % 10;

        if (h > 0) partStr += hundreds[h] + (num % 100 > 0 ? " و " : "");
        if (t > 1) {
            partStr += ones[o] + (o > 0 ? " و " : "") + tens[t];
        } else {
            partStr += ones[num % 100];
        }
        return partStr;
    }

    let result = "";
    let amount = Math.floor(n);
    let fractions = Math.round((n - amount) * 100);

    if (amount === 0) {
        result = "صفر";
    } else {
        // ملايين
        if (amount >= 1000000) {
            const m = Math.floor(amount / 1000000);
            if (m === 1) result += "مليون";
            else if (m === 2) result += "مليونان";
            else if (m <= 10) result += convertPart(m) + " ملايين";
            else result += convertPart(m) + " مليون";
            amount %= 1000000;
            if (amount > 0) result += " و ";
        }
        // آلاف
        if (amount >= 1000) {
            const k = Math.floor(amount / 1000);
            if (k === 1) result += "ألف";
            else if (k === 2) result += "ألفان";
            else if (k <= 10) result += convertPart(k) + " آلاف";
            else result += convertPart(k) + " ألف";
            amount %= 1000;
            if (amount > 0) result += " و ";
        }
        // مئات وآحاد
        if (amount > 0) result += convertPart(amount);
    }

    result = "فقط " + result + " " + currencyName;
    if (fractions > 0) {
        result += " و " + convertPart(fractions) + " هللة";
    }
    return result + " لا غير";
}
