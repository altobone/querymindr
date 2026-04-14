#!/bin/bash
set -e

ROOT_DIR="${ROOT_DIR:-/Volumes/Thunderbay}"

echo ""
echo "=============================="
echo "  File Finder - Local Start"
echo "=============================="
echo "Drive: $ROOT_DIR"
echo ""

# Kill anything already on these ports
lsof -ti:8080 | xargs kill -9 2>/dev/null || true
lsof -ti:5173 | xargs kill -9 2>/dev/null || true
sleep 1

echo "Starting API server on port 8080..."
PORT=8080 ROOT_DIR="$ROOT_DIR" pnpm --filter @workspace/api-server run dev &
API_PID=$!

echo "Waiting for API server to be ready..."
for i in {1..30}; do
  if curl -s http://localhost:8080/api/file-finder/stats > /dev/null 2>&1; then
    echo "API server ready!"
    break
  fi
  sleep 1
done

echo ""
echo "Starting frontend on port 5173..."
echo "-------------------------------"
echo "Open: http://localhost:5173/file-finder/"
echo "-------------------------------"
echo ""

PORT=5173 BASE_PATH=/file-finder/ pnpm --filter @workspace/file-finder run dev

# When frontend exits, kill API server
kill $API_PID 2>/dev/null || true
