#!/usr/bin/env python3
"""Generate the Capell root README banner with real logos supplied as model context.

Accepts multiple reference images (site hero style reference + Laravel mark +
Filament mark) alongside the canonical Capell wordmark, which is rasterized in
navy and white so the real mark is present during generation. See
readme-banner-recipe.md for the full workflow, including the mandatory
post-generation compositing pass.
"""

from __future__ import annotations

import argparse
import os
import subprocess
import sys
import tempfile
from pathlib import Path

from google import genai
from google.genai import types

MODEL_NAME = "gemini-3-pro-image-preview"


def image_part(path: Path) -> types.Part:
    mime_type = {
        ".jpg": "image/jpeg",
        ".jpeg": "image/jpeg",
        ".png": "image/png",
        ".webp": "image/webp",
    }.get(path.suffix.lower(), "image/png")

    return types.Part.from_bytes(data=path.read_bytes(), mime_type=mime_type)


def rasterize_logo(svg_path: Path, width: int, white: bool) -> Path:
    working_svg = svg_path
    temp_dir = Path(tempfile.mkdtemp(prefix="capell-logo-"))

    if white:
        white_svg = temp_dir / "capell-logo-white.svg"
        white_svg.write_text(svg_path.read_text().replace("#001d3d", "#ffffff"), encoding="utf-8")
        working_svg = white_svg

    output = temp_dir / ("capell-logo-white.png" if white else "capell-logo.png")
    subprocess.run(
        ["/opt/homebrew/bin/rsvg-convert", "-w", str(width), str(working_svg), "-o", str(output)],
        check=True,
    )

    return output


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate the Capell README banner.")
    parser.add_argument("--prompt-file", type=Path, required=True)
    parser.add_argument("--reference", type=Path, action="append", default=[])
    parser.add_argument("--logo", type=Path, default=Path(__file__).resolve().parent / "capell-logo.svg")
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--ratio", default="21:9")
    parser.add_argument("--size", default="4K")
    args = parser.parse_args()

    api_key = os.environ.get("GEMINI_API_KEY")
    if not api_key:
        print("GEMINI_API_KEY is not set.", file=sys.stderr)
        return 1

    contents: list[types.Part | str] = []
    for reference in args.reference:
        if not reference.exists():
            print(f"Reference missing: {reference}", file=sys.stderr)
            return 1
        contents.append(image_part(reference))

    contents.append(image_part(rasterize_logo(args.logo, width=1600, white=False)))
    contents.append(image_part(rasterize_logo(args.logo, width=1600, white=True)))
    contents.append(args.prompt_file.read_text(encoding="utf-8"))

    client = genai.Client(api_key=api_key)
    response = client.models.generate_content(
        model=MODEL_NAME,
        contents=contents,
        config=types.GenerateContentConfig(
            response_modalities=["IMAGE", "TEXT"],
            image_config=types.ImageConfig(aspect_ratio=args.ratio, image_size=args.size),
        ),
    )

    args.output.parent.mkdir(parents=True, exist_ok=True)

    text_response = None
    for part in response.candidates[0].content.parts:
        if part.inline_data and part.inline_data.mime_type.startswith("image/"):
            args.output.write_bytes(part.inline_data.data)
            print(args.output)
            return 0
        if part.text:
            text_response = part.text

    print(text_response or "No image generated.", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
