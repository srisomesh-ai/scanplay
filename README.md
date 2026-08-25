# ScanPlay — scanplay.in

Upload a target image + video → compiled in the browser → saved on server → QR generated.
Scanning the QR opens the player; pointing the camera at the printed image plays the video locked onto it.

## Structure
```
ar-video/
├── index.html        Admin studio (password) — upload, compile, save, list, QR
├── view.html         Public player  → view.html?id=XXXX
├── api.php           Backend: login | create | list | delete | get
├── .htaccess         Upload limits, .mind mime type, protects the JSON index
└── data/
    ├── accounts.json          index of all experiences
    └── {id}/
        ├── target.jpg            the printed picture
        ├── video.mp4             the video overlay
        └── targets.mind          MindAR compiled feature file
```

## Flow
1. Admin opens `index.html`, signs in (password in `api.php` → `ADMIN_PASS`).
2. Picks target image + MP4, clicks **Compile & save**.
   MindAR compiles the image to `targets.mind` in the browser (10–40 s), then all three files upload.
3. QR appears (encodes `https://yourdomain/…/view.html?id=XXXX`). Download and print it.
4. End user scans QR → `view.html` → taps **Start camera** → points at the picture → video plays.

## Deploy (Hostinger)
- Upload the folder, make sure `data/` is writable (755 is fine on Hostinger).
- Must be served over **HTTPS** (camera API requires it).
- Change `ADMIN_PASS` in `api.php`.
- If uploads over ~8 MB fail, raise `upload_max_filesize` / `post_max_size` in hPanel → PHP config.

## Tips for tracking
- Use detailed, high-contrast, non-repetitive images (photos, posters, packaging art).
- Plain logos, solid colours or glossy reflective prints track poorly.
- Video should have the same aspect ratio as the image so it covers it exactly.
- Keep videos short and under 60 MB; H.264 MP4 works on all phones.

## Libraries (CDN)
- MindAR 1.2.5 (image tracking + compiler) · A-Frame 1.5.0 · qrcodejs
