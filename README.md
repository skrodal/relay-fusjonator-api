# relay-fusjonator-api

Dette APIet er registrert i UNINETT Connect tjenesteplattform og benyttes av tjeneste "RelayFusjonator".

APIet mottar og prosesserer henvendelser fra en `klient` (eks. https://github.com/skrodal/relay-fusjonator-client) registrert i UNINETT Connect tjenesteplattform som har tilgang til dette APIet.
 
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

- Karius og Baktus har ingen konto fra før, så vi kan regne med at disse to blir ignorert. 
- Simon og Renlin har konto og vi kan forvente at nytt brukernavn er ledig.
- Siste linje vet vi kommer til å skjære seg...

```
     karius@hin.no, karius@uit.no
     baktus@hin.no, baktus@uit.no
     simon@uninett.no, simon@uit.no
     renlin@uninett.no, renlin@uit.no
     simon@uninett.no, renlin@uninett.no
```

Svar fra API etter å ha sjekket med Relay DB:

- Første to linjer ble ignorert (de har ikke konto)
- Neste to linjer er OK - begge brukere har konto og nytt brukernavn er ikke tatt i bruk enda. 
- Siste linje: kollisjon siden nytt brukernavn allerede er tatt i bruk!

Kun brukere i "ready"-segmentet vil bli sent til API i neste kall for migrering.

```
// TODO: Eksempeloutput her
```


### Relatert

For mer informasjon om APIets funksjon, se https://github.com/skrodal/relay-fusjonator-client. 

