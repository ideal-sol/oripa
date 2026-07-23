# Admin Regression Checklist

## Rollback Recovery Checklist

- [x] `/admin/guide` returns without 504. Confirmed `200` after warmup.
- [x] `/admin/gachas` returns without 504. Confirmed `200` after warmup.
- [x] `/admin/gachas/4/edit` returns without 504. Confirmed `200` after warmup.
- [x] `/admin/users` returns without 504. Confirmed `200` after warmup.
- [x] `/admin/shipping` returns without 504. Confirmed `200` after warmup.
- [x] `/admin/settings/line` returns without 504. Confirmed `200` after warmup.
- [ ] Left menu opens the expected main admin pages. Needs browser click confirmation.
- [ ] List edit buttons open the expected edit/detail pages. Direct edit URL confirmed; browser click confirmation remains.
- [x] Frontend logs do not show admin route conflict errors after the final restart.
- [ ] Frontend logs do not show an abnormal compile or redirect loop. Initial admin compile remained very slow, but warm requests returned in practical range.

## Notes

- ADMIN-REF-001 route split is deferred.
- Current active admin implementation is the stable `admin-dashboard.tsx` plus `admin/[[...segments]]/page.tsx` structure.
- Initial Next.js dev compilation of the giant admin catch-all route can still exceed 60 seconds after restart, causing temporary 504 until warm.
- Browser click behavior still needs human confirmation after server recovery.
