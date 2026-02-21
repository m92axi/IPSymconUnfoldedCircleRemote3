# Unfolded Circle Remote 3

[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Version](https://img.shields.io/badge/Symcon%20Version-%3E%208.2-green.svg)](https://www.symcon.de/service/dokumentation/installation/)

Modul für IP-Symcon zur Integration der **Unfolded Circle Remote 3** sowie des **Remote Dock 3**.

Das Modul ermöglicht die bidirektionale Kommunikation mit der Remote 3 über WebSocket, das Empfangen von Echtzeit-Events
sowie das Auslösen von Aktivitäten und Befehlen aus IP-Symcon.

---

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)  
2. [Voraussetzungen](#2-voraussetzungen)  
3. [Installation](#3-installation)  
4. [Einrichtung in IP-Symcon](#4-einrichtung-in-ip-symcon)
5. [Instanzenübersicht](#5-instanzenübersicht)
6. [Anhang](#6-anhang)  

---

## 1. Funktionsumfang

Mit der Unfolded Circle Remote 3 ist es möglich, AV-Geräte, Aktivitäten und Smart-Home-Funktionen zentral zu steuern.

Dieses Modul ermöglicht:

- Aufbau einer WebSocket-Verbindung zur Remote 3
- Empfang von Echtzeit-Events (z. B. Aktivitätenwechsel, Batteriestatus, Displaystatus, Benutzereingaben)
- Auslösen von Aktivitäten und Systembefehlen
- Integration in Automationen, Skripte, Alexa oder HomeKit
- Verwaltung von Remote 3- und Dock 3-Instanzen
- Optionale automatische Geräteerkennung über Discovery (mDNS)

---

## 2. Voraussetzungen

- IP-Symcon >= 8.2
- Unfolded Circle Remote 3
- Optional: Remote Dock 3
- Netzwerkzugriff zwischen IP-Symcon und Remote 3

---

## 3. Installation

### a. Laden des Moduls

Die WebConsole von IP-Symcon unter  
_http://{IP-Symcon IP}:3777/console/_ öffnen.

Anschließend oben rechts auf das Symbol für den Modulstore klicken.

![store_icon.png](img/store_icon.png)

Im Suchfeld nun

```
Unfolded Circle Remote 3
```

eingeben.

![module_store_search.png](img/module_store_search.png)

Das Modul auswählen und auf _Installieren_ klicken.

![install.png](img/install.png)

---

## 4. Einrichtung in IP-Symcon

Nach der Installation wird automatisch eine **Discovery-Instanz** erstellt.

Diese sucht im lokalen Netzwerk nach:

- Remote 3-Geräten
- Remote Dock 3-Geräten

Wird ein Gerät gefunden, kann über _Erstellen_ eine entsprechende Instanz angelegt werden:

- **Remote 3-Instanz**
- **Remote Dock 3-Instanz**

Je nach Konfiguration wird eine WebSocket-Client-Instanz zur Kommunikation verwendet oder automatisch erstellt.

Weitere Einstellungen erfolgen direkt in der jeweiligen Instanz.

### Ersteinrichtung

#### Remote 3 einrichten

Achten Sie darauf, dass sich die Remote 3 während des Einrichtungsprozesses im Wachzustand befindet. Stellen Sie diese
wenn möglich in das Dock 3, um eine stabile Strom- und Netzwerkversorgung sicherzustellen.

Legen Sie zunächst mit einem Rechtsklick im Objektbaum über *Objekt hinzufügen* → *Instanz* eine neue
*Remote 3 Discovery*-Instanz an.

![Add Remote Discovery.png](img/Add%20Remote%20Discovery.png)

Nach dem Öffnen der Discovery-Instanz geben Sie oben das vierstellige Webpasswort der Remote 3 ein, das auf dem Display
der Remote 3 angezeigt wird.

![Discovery Eingabe Webpasswort.png](img/Discovery%20Eingabe%20Webpasswort.png)

Wählen Sie nun aus den gefundenen Geräten das Gerät aus, das Sie in Symcon neu anlegen möchten.

![Remote 3 Discovery.png](img/Remote%203%20Discovery.png)

Klicken Sie anschließend auf *Erstellen*.

Bevor Sie fortfahren, stellen Sie sicher, dass sich die Remote 3 weiterhin im Wachzustand befindet und das Display
aktiv ist.

Öffnen Sie nun im Objektbaum die Instanz *Remote 3 Core Manager Remotexxx*.
Ganz oben finden Sie den Hinweis:

![Instanz konfigurieren.png](img/Instanz%20konfigurieren.png)

Klicken Sie hier auf *Konfigurieren*.
Es öffnet sich ein neues Fenster, in dem Sie aufgefordert werden, mit *OK* zu bestätigen.

Aktivieren Sie nun die Instanz, indem Sie den Schalter auf

![Aktiv.png](img/Aktiv.png)

*Ein* setzen.

Anschließend klicken Sie auf

![Apply Changes.png](img/Apply%20Changes.png)

*Änderungen übernehmen*.

Die Remote 3 ist nun für die Nutzung in Symcon konfiguriert. Sie können in die Instanz der Remote 3 im Objektbaum
wechseln und dort die Systeminformationen der Remote 3 einsehen.

Die Remote 3-Instanz dient dazu, Systeminformationen wie den Online-Status, den Batteriestatus oder weitere
Geräteinformationen anzuzeigen. Ändert sich ein Zustand der Remote 3, wird dieser automatisch in Symcon aktualisiert.

#### Remote 3 Integration Driver einrichten

Der *Remote 3 Integration Driver* dient dazu, Geräte aus Symcon in die Remote 3 zu importieren, damit diese über die
Benutzeroberfläche der Remote 3 gesteuert werden können.

Legen Sie mit einem Rechtsklick im Objektbaum über *Objekt hinzufügen* → *Instanz* eine neue
*Remote 3 Integration Driver*-Instanz an.

Es öffnet sich folgendes Fenster:

![Server Socket.png](img/Server%20Socket.png)

Aktivieren Sie die Instanz, indem Sie den Schalter auf *Ein* setzen, und bestätigen Sie mit *OK*.

In der Instanz finden Sie nun eine Auswahl von Gerätetypen, die von der Remote 3 angesteuert werden können.

Damit die Remote 3 ein Gerät in Symcon steuern darf und dieses auf der Remote 3 zum Import zur Verfügung steht,
muss das Gerät im *Remote 3 Integration Driver* unter dem entsprechenden Gerätetyp hinzugefügt werden.

![Remote 3 Integration Driver.png](img/Remote%203%20Integration%20Driver.png)

Öffnen Sie den gewünschten Gerätetyp und fügen Sie über *Hinzufügen* das entsprechende Gerät hinzu.

Je nach Gerätetyp müssen unterschiedliche Variablen des Geräts einmalig zugewiesen werden.

---

## 5. Instanzenübersicht

### Remote 3 Core Manager

Zentrale Kommunikationsinstanz. Baut die WebSocket-Verbindung zur Remote auf und verteilt eingehende Events an
untergeordnete Instanzen.

### Remote 3 Device

Repräsentiert eine physische Remote 3 und verarbeitet gerätespezifische Events.

### Remote Dock 3

Repräsentiert ein Dock 3-Gerät.

### Remote Dock 3 Manager

Erweiterte Verwaltungsinstanz für Dock-spezifische Funktionen.

### Remote 3 Integration Driver

Ermöglicht die Anbindung externer Systeme über JSON-RPC.

### Remote 3 Configurator

Unterstützt bei der Einrichtung und Verwaltung von Geräten.

---

## 6. Anhang

### GUID

Die GUIDs der einzelnen Instanzen sind im Modulcode definiert und können bei Bedarf in der jeweiligen module.json-Datei
eingesehen werden.

---

*Hinweis: Diese Dokumentation stellt ein Grundgerüst dar und wird im Rahmen der Weiterentwicklung des Moduls
kontinuierlich erweitert.*