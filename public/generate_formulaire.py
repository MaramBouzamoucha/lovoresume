import pdfkit
import sys
import os

def generate_pdf(html_path, pdf_path):
    try:
        # Configuration pour Windows - adaptez le chemin si nécessaire
        wkhtml_path = r'C:\Users\User\Downloads\wkhtmltox-0.12.6-1.msvc2015-win64.exe'

        if not os.path.exists(wkhtml_path):
            raise Exception("wkhtmltopdf non trouvé à l'emplacement: " + wkhtml_path)

        config = pdfkit.configuration(wkhtmltopdf=wkhtml_path)

        options = {
            'page-size': 'A4',
            'margin-top': '2cm',
            'margin-right': '2cm',
            'margin-bottom': '2cm',
            'margin-left': '2cm',
            'encoding': 'UTF-8',
            'enable-local-file-access': None
        }

        pdfkit.from_file(html_path, pdf_path, options=options, configuration=config)
        return True
    except Exception as e:
        print(f"Erreur: {str(e)}", file=sys.stderr)
        return False

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage: python generate_formulaire.py <input.html> <output.pdf>")
        sys.exit(1)

    if not generate_pdf(sys.argv[1], sys.argv[2]):
        sys.exit(1)