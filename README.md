# relay-fusjonator-api

Dette APIet er registrert i UNINETTs tjenesteplattform Dataporten og benyttes av tjeneste "RelayFusjonator".

APIet mottar og prosesserer henvendelser fra en `klient` (eks. https://github.com/skrodal/relay-fusjonator-client) registrert i UNINETT Dataporten som har tilgang til dette APIet.
 
Dataflyt mellom `klient`<->`API`<->`Relay` er som følger:


1. `Klient` sender brukerliste til API med følgende CSV format (`current_login, current_email, new_login, new_email`):
 
2. API kryssjekker hver linje i brukerlista med TechSmith Relay som følger:

- Eksisterer konto med brukernavn?
    - NEI: skip til neste linje (vi trenger ikke gjøre noe som helst med dette brukernavnet)
    - JA: bruker har konto. Sjekk for sikkerhets skyld om NYTT_brukernavn også eksisterer:
        - NEI: bra, oppdater brukernavn/epost i liste over kontoer som kan og skal fusjoneres
        - JA: ops! Nytt brukernavn eksisterer allerede - altså kan ikke gammel og ny konto migreres... 

3. API sender tilbake til klienten ei liste med brukerkontoer som kan migreres, og som kan kontrolleres av brukeren. I tillegg synliggjøres liste over problematiske kontoer (der begge brukernavn allerede eksisterer).

4. Klient sender så nytt kall til API med den nye lista over kontoer som kan migreres.

5. API oppdaterer hver og en bruker i TechSmith Relay database.

6. Når #5 er ferdig sender API svar til klient med status (liste over alle brukernavn som ble migrert).

7. Ferdig!


### Eksempelsvar fra `API` til `klient`:

Eksempel med tulle-data for å illustrere første oppslag i API: 

- Bør Børson har ingen konto fra før, så vi kan regne med at første linje legges i liste over kontoer som kan ignoreres.
- `simon1@uninett.no` eksisterer, mens `simon1@feide.no` er ledig. Denne kontoen vil legges i liste for migrering.
- Siste linje vil skjære seg fordi begge kontoer eksisterer - legges i liste over problematiske kontoer.

```
    borborson@uninett.no, bor.borson@uninett.no, borborson@feide.no, bor.borson@feide.no
    simon1@uninett.no, simon.skrodal@uninett.no, simon1@feide.no, simon.skrodal@feide.no
    renlin@uninett.no, renate.langeland@uninett.no, simon@uninett.no, simon.skrodal@uninett.no
```

Svar fra API etter å ha sjekket med Relay DB:

```
{
  "status": true,
  "data": {
    "ignore": {
      "borborson@uninett.no": {
        "message": "Hopper over siden ingen konto er registrert for dette brukernavnet.",
        "account_info_current": {
          "username": "borborson@uninett.no",
          "email": "bor.borson@uninett.no"
        },
        "account_info_new": {
          "username": "borborson@feide.no",
          "email": "bor.borson@feide.no"
        }
      }
    },
    "ok": {
      "simon1@uninett.no": {
        "message": "Klar for fusjonering til nytt brukernavn!",
        "account_info_current": {
          "username": "simon1@uninett.no",
          "email": "simon.skrodal@uninett.no"
        },
        "account_info_new": {
          "username": "simon1@feide.no",
          "email": "simon.skrodal@feide.no"
        }
      }
    },
    "problem": {
      "renlin@uninett.no": {
        "message": "Kan ikke migrere! Nytt brukernavn er allerede blitt tatt i bruk.",
        "account_info_current": {
          "username": "renlin@uninett.no",
          "email": "renate.langeland@uninett.no"
        },
        "account_info_new": {
          "username": "simon@uninett.no",
          "email": "simon.skrodal@uninett.no"
        }
      }
    }
  }
}
```

Kun brukere i "ok"-segmentet vil bli sent til API i neste kall for migrering.


### Relatert

For mer informasjon om APIets funksjon, se https://github.com/skrodal/relay-fusjonator-client. 

