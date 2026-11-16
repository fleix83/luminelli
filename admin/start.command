#!/bin/bash
# Studio Luminelli admin: double-click to start, close the window to stop.
# Runs PHP's built-in server as you, so the admin can write the site files and publish with your git login.
cd "$(dirname "$0")/.." || exit 1
PHP=$(command -v php || echo /Applications/XAMPP/xamppfiles/bin/php)
PORT=8765
echo "Admin: http://localhost:$PORT/admin/"
echo "Vorschau: http://localhost:$PORT/"
echo "Zum Beenden dieses Fenster schliessen."
( sleep 1; open "http://localhost:$PORT/admin/" ) &
exec "$PHP" -S "localhost:$PORT" -t .
