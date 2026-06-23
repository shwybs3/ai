#!/usr/bin/env python3
"""
أداة ضغط جماعية للصور المرفوعة قديماً على الموقع (قبل تفعيل الضغط التلقائي في index.php).
تُعيد ترميز كل صورة في مجلدات uploads/ بجودة مضغوطة وتُصغّر أبعادها إذا تجاوزت الحد الأقصى،
بنفس منطق compress_image_file() في index.php لكن كأداة سطر أوامر يشغّلها الأدمن مرة واحدة
أو عبر cron دوري لتنظيف أي صور جديدة تتجاوز الحد (مثل صور Satofill المخزّنة مؤقتاً).

التثبيت:
    pip install Pillow

الاستخدام:
    python3 tools/bulk_compress_images.py [مسار_مجلد_uploads] [--max-dim 1280] [--quality 75] [--dry-run]
"""
import argparse
import os
import sys

try:
    from PIL import Image
except ImportError:
    sys.exit("يلزم تثبيت مكتبة Pillow أولاً: pip install Pillow")

IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".webp", ".gif"}

# مجلدات أصغر تحتاج جودة/أبعاد أصغر (الافاتار والشعار صور دائرية/صغيرة لا تحتاج دقة عالية)
DIR_OVERRIDES = {
    "avatars": (600, 80),
    "site": (400, 78),
}


def compress_image(path: str, max_dim: int, quality: int, dry_run: bool) -> tuple[int, int]:
    before = os.path.getsize(path)
    try:
        with Image.open(path) as im:
            w, h = im.size
            fmt = im.format
            if w > max_dim or h > max_dim:
                ratio = min(max_dim / w, max_dim / h)
                im = im.resize((max(1, int(w * ratio)), max(1, int(h * ratio))), Image.LANCZOS)
            if dry_run:
                return before, before
            save_kwargs = {}
            if fmt in ("JPEG", "WEBP"):
                save_kwargs["quality"] = quality
                save_kwargs["optimize"] = True
            elif fmt == "PNG":
                save_kwargs["optimize"] = True
            im.save(path, format=fmt, **save_kwargs)
    except Exception as e:
        print(f"  تخطّي {path}: {e}")
        return before, before
    after = os.path.getsize(path)
    return before, after


def main():
    parser = argparse.ArgumentParser(description="ضغط جماعي للصور المرفوعة على الموقع")
    parser.add_argument("uploads_dir", nargs="?", default="uploads", help="مسار مجلد uploads (افتراضي: uploads)")
    parser.add_argument("--max-dim", type=int, default=1280, help="أقصى عرض/ارتفاع بالبكسل")
    parser.add_argument("--quality", type=int, default=75, help="جودة JPEG/WEBP (1-100)")
    parser.add_argument("--dry-run", action="store_true", help="عرض النتائج المتوقعة بدون تعديل الملفات فعلياً")
    args = parser.parse_args()

    if not os.path.isdir(args.uploads_dir):
        sys.exit(f"المجلد غير موجود: {args.uploads_dir}")

    total_before = total_after = count = 0
    for root, _dirs, files in os.walk(args.uploads_dir):
        subdir = os.path.basename(root)
        max_dim, quality = DIR_OVERRIDES.get(subdir, (args.max_dim, args.quality))
        for fname in files:
            ext = os.path.splitext(fname)[1].lower()
            if ext not in IMAGE_EXTS:
                continue
            path = os.path.join(root, fname)
            before, after = compress_image(path, max_dim, quality, args.dry_run)
            total_before += before
            total_after += after
            count += 1
            if before != after:
                saved_pct = 100 * (1 - after / before) if before else 0
                print(f"  {path}: {before // 1024}KB -> {after // 1024}KB ({saved_pct:.0f}% أقل)")

    print(f"\nتمت معالجة {count} صورة.")
    if total_before:
        saved_pct = 100 * (1 - total_after / total_before)
        print(f"الحجم الكلي: {total_before // 1024}KB -> {total_after // 1024}KB ({saved_pct:.0f}% توفير)")
    if args.dry_run:
        print("(--dry-run: لم يتم تعديل أي ملف فعلياً)")


if __name__ == "__main__":
    main()
