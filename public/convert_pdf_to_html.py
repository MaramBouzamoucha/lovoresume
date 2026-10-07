import sys
import fitz
import docx

def convert_pdf_to_html(pdf_path):
    doc = fitz.open(pdf_path)
    html_content = "<!DOCTYPE html>\n<html lang='fr'>\n<head>\n"
    html_content += "<meta charset='UTF-8'>\n<title>template</title>\n</head>\n<body>\n<div class='container'>\n"
    for page_num, page in enumerate(doc):
        text = page.get_text("text").strip().replace("\n", "<br>")


        html_content += f"<h2>Page {page_num + 1}</h2>\n<p>{text}</p>\n"

    html_content += "</div>\n</body>\n</html>"
    return html_content

def convert_docx_to_html(docx_path):
    doc = docx.Document(docx_path)
    html_content = "<!DOCTYPE html>\n<html lang='fr'>\n<head>\n"
    html_content += "<meta charset='UTF-8'>\n<title>template</title>\n</head>\n<body>\n<div class='container'>\n"

    for para in doc.paragraphs:
        html_content += f"<p>{para.text}</p>\n"

    html_content += "</div>\n</body>\n</html>"
    return html_content

def convert_to_html(file_path):
    if file_path.lower().endswith(".pdf"):
        return convert_pdf_to_html(file_path)
    elif file_path.lower().endswith(".docx"):
        return convert_docx_to_html(file_path)
    else:
        return "Format non pris en charge."

if __name__ == "__main__":
    sys.stdout.reconfigure(encoding='utf-8')
    file_path = sys.argv[1]
    html_output = convert_to_html(file_path)
    print(html_output)
