#!/usr/bin/env bash
cd /home/rakilluy/greenlightinduction.rakibhasaan.com/slate || exit 1
fail=0
for f in plugins/booking/*.php plugins/booking/admin/*.php plugins/booking/public/*.php; do
  out=$(php -l "$f" 2>&1)
  if ! echo "$out" | grep -q "No syntax errors"; then
    echo "LINT FAIL: $f"
    echo "$out"
    fail=1
  fi
done
[ "$fail" -eq 0 ] && echo "ALL BOOKING PHP OK"
