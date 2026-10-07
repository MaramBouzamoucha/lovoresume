import sys
import fitz  # PyMuPDF
import docx
import json
def extract_text(file_path):
    text = ""
    try:
        if file_path.endswith(".pdf"):
            with fitz.open(file_path) as doc:
                for page in doc:
                    text += page.get_text("text") + "\n"
        elif file_path.endswith(".docx"):
            doc = docx.Document(file_path)
            for paragraph in doc.paragraphs:
                text += paragraph.text + "\n"
        else:
            text = "Format non supporté"
    except Exception as e:
        text = f"Erreur : {e}"
    print(json.dumps({"text": text}))
if __name__ == "__main__":
    extract_text(sys.argv[1])
