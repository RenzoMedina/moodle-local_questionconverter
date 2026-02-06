# Moodle Question Converter #

## 📌 Description ##

Moodle that automates the creation of questions from PDF documents. It extracts questions, identifies indicators or sections, classifies content, and generates questions directly in the course question bank, without the need to import XML or GIFT files.

It is designed for teaching teams or managers who work with large volumes of questions and want to standardize and streamline the creation of assessments.

## 🚀 Main features ##

* Automatic extraction from PDF: Detects questions, alternatives, and correct answers.

* Classification by indicators or sections: Organizes questions according to the document structure.

* Irect creation in the question bank: Uses Moodle's internal API to insert questions without manual steps.

* Optimized for educational institutions: Optimized for educational institutions

* Compatible with multiple question types: multichoice, essay and truefalse (depending on the PDF content).

* Analyze the number of alternatives, such as 3 or 4. 

* Compatibility for Spanish and English

## 🌐 Future scalability ##

* Extract images and insert them into the questions

* Managing multiple PDFs 

* Handling other types of formats

## 🛠️ Requirements ##

* Moodle "^4.x"
* PHP "^8.x"
* Node (npm to TailwindCSS)

## PDF Template Examples

### English
- [Standard Format](https://templatespdfplugin.blob.core.windows.net/english/Template_question_normal.pdf) - Questions without evaluation indicators
- [With Indicators](https://templatespdfplugin.blob.core.windows.net/english/Template_question_indicators.pdf) - Questions grouped by evaluation indicators

### Spanish
- [Formato Estándar](https://templatespdfplugin.blob.core.windows.net/spanish/Template_cuestionario_normal.pdf) - Preguntas sin indicadores de evaluación
- [Con Indicadores](https://templatespdfplugin.blob.core.windows.net/spanish/Template_cuestionario_indicadores.pdf) - Preguntas agrupadas por indicadores de evaluación

## Installing via uploaded ZIP file ##

1. Log in to your Moodle site as an admin and go to _Site administration >
   Plugins > Install plugins_.
2. Upload the ZIP file with the plugin code. You should only be prompted to add
   extra details if your plugin type is not automatically detected.
3. Check the plugin validation report and finish the installation.

## Installing manually ##

The plugin can be also installed by putting the contents of this directory to

    {your/moodle/dirroot}/local/questionconverter

Afterwards, log in to your Moodle site as an admin and go to _Site administration >
Notifications_ to complete the installation.

Alternatively, you can run

    $ php admin/cli/upgrade.php

to complete the installation from the command line.

## License ##

2026 Renzo Medina <medinast30@gmail.com>

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE.  See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program.  If not, see <https://www.gnu.org/licenses/>.
