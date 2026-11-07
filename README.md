# ESP-Sprachassistent

Symcon-Modul für die lokale Musiksteuerung eines ESP-Sprachassistenten. Die Kachel verwendet die auf dem Lautsprecher eingerichteten Plex- und LMS-Server, Radio Browser und die freigegebenen Wiedergabeziele. Quelle und Ausgabe werden am ESP gespeichert und auch von seiner Sprachsteuerung verwendet.

## Installation

1. In Symcon unter **Kern-Instanzen → Modules** das Repository `https://github.com/mb-stern/ESP-Sprachassistent.git` hinzufügen. Für Entwicklungsstände in der Modulverwaltung den Zweig `development` wählen, für Vorabtests `beta`, für freigegebene Versionen `main`.
2. Im Objektbaum eine Instanz **ESP-Sprachassistent** anlegen. Pro ESP eine Instanz verwenden.
3. Die lokale Webadresse des Lautsprechers und bei Bedarf sein Web-Passwort eintragen. Einstellungen übernehmen und **Gespeicherte Verbindung prüfen** anklicken.
4. Die Instanz in der Kachelvisualisierung öffnen. Musikquellen und externe Wiedergabeziele zuerst im ESP-Webinterface einrichten und freigeben.

Die erste Modulversion setzt die Kachelvisualisierung mit HTML-SDK (Symcon 7.1 oder neuer) und die Musik-Webschnittstelle der Firmware Beta 0.40.4 voraus. Symcon muss die lokale ESP-Adresse erreichen. Bei einer HTTP-Adresse wird auch das optionale Web-Passwort per HTTP-Basic-Anmeldung im lokalen Netz verwendet.

## Bedienung

- Eine oder mehrere Suchquellen speichern und die Suche bei Bedarf auf Interpret, Album, Titel oder Radiosender eingrenzen. Interpret und Album öffnen die nächste Ebene. Alben und Interpreten lassen sich auch zufällig abspielen.
- Freigegebene Ausgaben eines Systems auswählen. Die Firmware prüft die Gruppierung und führt die Wiedergabe aus. **Letztes Radio** verwendet Sender und Ausgabe der letzten Radiowiedergabe.
- Die Kachel zeigt die vom ESP gemeldeten laufenden Wiedergaben. **Status aktualisieren** fragt die Ausgabegeräte über den ESP neu ab.

Für Symcon-Skripte stehen `ESPVOICE_PlayRadio($InstanzID)` und `ESPVOICE_ControlPlayback($InstanzID, 'stop')` zur Verfügung. Weitere Steuerbefehle sind `pause`, `resume`, `previous` und `next`.

Die drei Zweige betreffen die Modulverteilung. Firmware-BINs und der OTA-Kanal werden separat bereitgestellt.
