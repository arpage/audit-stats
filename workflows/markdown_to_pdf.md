# Workflow: Convert Markdown to PDF

## Objective
Convert a Markdown (`.md`) file to a PDF document.

## Required Inputs
- Path to the source `.md` file

## Steps

1. Run the tool:
   ```bash
   php tools/markdown_to_pdf.php <path/to/file.md> [path/to/output.pdf]
   ```
   - If the output path is omitted, the PDF is saved in the same directory as the input, with the same base name.

2. Confirm the PDF was created and check the file size is reasonable (non-zero).

## Dependencies
- `pandoc` must be installed (`which pandoc` to verify)
- A LaTeX engine must be available for pandoc's default PDF output (e.g. `texlive-latex-base`)

## Edge Cases
- If pandoc fails with a LaTeX error, check that a LaTeX engine is installed.
- Input file must have a `.md` extension.
