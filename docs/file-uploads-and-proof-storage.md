# File uploads and proof storage

- Proof screenshots: JPEG, PNG or WebP, max `tasks.proof_max_bytes`
  (default 2 MB, admin-editable up to 10 MB; PHP `upload_max_filesize` and
  `post_max_size` must be at least as large — cPanel defaults are usually
  fine for 2 MB).
- The type comes from the bytes (finfo + getimagesize), never the file name
  or browser type. Minimum 50×50, maximum 10,000 px a side / 40 MP.
- With GD (enabled on ea-php84) every image is decoded and re-encoded, which
  strips EXIF/location data and anything appended to the file.
- The exact same uploaded image (SHA-256 of the upload) cannot be used for
  another submission by anyone (`Tasks\FakeProofGuard`).
- Files are written to `<private root>/proofs/YYYY/MM/<32 hex>.<ext>`, mode
  0600, directories 0700. Private root = `storage.private_root` in
  `app.local.php`, else env `YOLOTASK_STORAGE_DIR`, else
  `~/yolotask-v2-live/runtime` (created by the release script). It is outside
  every document root.
- The database stores the relative path, size, dimensions, upload hash and
  stored hash (`submission_proofs`).
- Files are served only by `proof.php?id=` on the earner, advertiser and
  admin portals after a check: the uploading earner, the campaign's
  advertiser, or an admin. Anyone else gets 404 (signed-out 403). Responses
  are `Cache-Control: private`, `nosniff`, sandboxed CSP.
- If a submission fails after the file was written, the file is deleted.
