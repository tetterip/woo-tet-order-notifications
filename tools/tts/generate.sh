#!/usr/bin/env bash
# Generates the spoken "new order" messages in assets/sounds/ with OpenAI text-to-speech.
# Dev only (tools/ is not in the release zip). Run from the plugin root:
#   OPENAI_API_KEY=sk-... bash tools/tts/generate.sh [voice]
# Default voice: coral. Others: alloy, ash, ballad, echo, fable, nova, onyx, sage, shimmer, verse.
# Listen to both files before committing them.
set -euo pipefail

: "${OPENAI_API_KEY:?Set OPENAI_API_KEY}"
VOICE="${1:-coral}"
MODEL="gpt-4o-mini-tts"
OUT="assets/sounds"
mkdir -p "$OUT"

say() { # lang, text, instructions
  local body
  body=$(printf '{"model":"%s","voice":"%s","input":"%s","instructions":"%s","response_format":"mp3"}' \
    "$MODEL" "$VOICE" "$2" "$3")
  curl -sS --fail-with-body https://api.openai.com/v1/audio/speech \
    -H "Authorization: Bearer $OPENAI_API_KEY" \
    -H "Content-Type: application/json" \
    --data-binary "$body" \
    -o "$OUT/new-order-$1.mp3"
  echo "$OUT/new-order-$1.mp3 ($(wc -c < "$OUT/new-order-$1.mp3") bytes)"
}

say el "Έχετε νέα online παραγγελία!" \
  "Native Greek speaker. Warm, friendly and clear, like a shop assistant giving good news. Natural pace, slightly upbeat. Pronounce 'online' the Greek way: όνλάιν."
say en "You have a new online order!" \
  "Warm, friendly and clear, like a shop assistant giving good news. Natural pace, slightly upbeat."
