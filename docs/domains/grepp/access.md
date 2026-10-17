> Από το regweb.ics.forth.gr/hostmaster (Τεκμηρίωση → Περιβάλλον Λειτουργίας), όπως το έδωσε ο ιδιοκτήτης 2026-10-18.

###### [Αρχική](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW-cDmsoPwnHNfNkCuWbkduQpn-kOwpwhLuLsRJEwyPKpNkqXQJZ0wrC8Uisnw%3D%3D) > [Περιβάλλον Λειτουργίας](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW_IBYQTOsutQSpUu1Bn4XmwneB82juEdfTOe82FsMJKEyOHvlwleoq-Xy_Ns8o%3D) > Πρόσβαση στα Συστήματα Μητρώου

---

Το Μητρώο Ονομάτων Internet .gr και .ελ παρέχει στους καταχωρητές το σύστημα παραγωγής και το σύστημα δοκιμών. Για την πρόσβασή σας στα συστήματα του Μητρώου είναι απαραίτητο:

1.  Να συνδέεστε από στατική IP διεύθυνση δηλωμένη στο Μητρώο.

    Μπορείτε να δηλώσετε έως δέκα (10) στατικές IP διευθύνσεις για την πρόσβασή σας στο σύστημα παραγωγής και αντίστοιχα στο σύστημα δοκιμών του Μητρώου. Τα συστήματά μας ενημερώνονται για αλλαγές στις ΙΡ Δευτέρα έως Παρασκευή στις 08.00, 10.00, 12.00, 15.00, 18.00 (ώρες UTC/GMT).

    Μπορείτε να προσθέσετε / αφαιρέσετε IP για την πρόσβασή σας στο σύστημα παραγωγής και στο σύστημα δοκιμών του Μητρώου από τους αντίστοιχους διαδικτυακούς τόπους ([https://regweb.ics.forth.gr/hostmaster](https://regweb.ics.forth.gr/hostmaster) και [https://uat-regweb.ics.forth.gr/hostmaster](https://uat-regweb.ics.forth.gr/hostmaster)), από το μενού Εργαλεία -> Αλλαγή IP Καταχωρητή ή να αποστείλετε σχετικό αίτημα στο τμήμα εξυπηρέτησης καταχωρητών του Μητρώου ([gr-tech@ics.forth.gr](mailto:gr-tech@ics.forth.gr)) από email διεύθυνση επικοινωνίας που έχετε δηλώσει ως καταχωρητής.

2.  Να διαθέτετε λογαριασμό χρήστη (περισσότερες πληροφορίες σχετικά με τη διαχείριση λογαριασμών χρήστη θα βρείτε στην ενότητα "Διαχείριση στοιχείων καταχωρητή").

Η **υπηρεσία EPP** του Μητρώου βασίζεται στο πρωτόκολλο EPP ([RFC3730](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW_Et-tYivsG2_NmLzjbQfE02legoG2TzKRRhSZhbhdltrMZop04HhA%3D), [RFC3731](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW-JDEr-94F3aBqLMP5N3s3P8qQeLUCEIVvoaTn9eQl0zWddeS3wmSg%3D), [RFC3732](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW8bRZStdyfjebYBk0LHvau_Rfh-OSTTYGQyRW77ZDbQZIGggeTxVB0%3D), [RFC3733](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW-rNPtR9kmWniGoQlsTnFSz3u4gVaUtsGkUagW67ouAJSLymiSWic4%3D), [RFC3734](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW_grlmfTXa3xLPUKjjeHBDnmdFHGRdx6ay1_mJVzeHUdteMhxs-Uaw%3D), [RFC3735](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW9O7-LXk3524RB8CL5wU2zgaXoQN7dJ3rdSzEeqbbD52WDccEVPrQE%3D) του IETF) και χρησιμοποιείται για τη διασύνδεση του συστήματος του Μητρώου με τα συστήματα των καταχωρητών. Μέσω του πρωτοκόλλου EPP (παρέχεται υπό την μορφή XML Web service πάνω από το πρωτόκολλο HTTPS) δίνεται η δυνατότητα διάθεσης των υπηρεσιών του Μητρώου στους τελικούς φορείς ονομάτων, μέσω κατάλληλων εφαρμογών πελάτη EPP των καταχωρητών. Για να επιτευχθεί αυτό θα πρέπει ο καταχωρητής να αναπτύξει ή να προμηθευτεί εφαρμογή πελάτη που βασίζεται στο πρωτόκολλο EPP και είναι συμβατή με το Μητρώο.

Τα RFCs ([RFC5910](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW_6E0ebEtVCSqAfE1MjNv_Rs0fiRTsfkODe0UYVqZVxRLC-xz6bsyU%3D), [RFC4034](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW_QpKXCBmWtzJG8h-fwRs-TIgrLm-1XuHV1wbulVdim5j_OQff-sgs%3D)) περιγράφουν τις επεκτάσεις ασφαλείας **DNSSEC**. Το Μητρώο έχει υλοποίησει τις [επεκτάσεις DNSSec για το πρωτόκολλο EPP](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW-nNtaTFRJgANrU8u3obn3pSGuduN1EMcSY4GGAEutOKuZlRSTGkA7_LeshjUfdeV-gwKK9BHC5-L8-URA%3D).

Το Μητρώο παρέχει την **Υπηρεσία Αυξημένης Ασφάλειας (ΥΑΑ)** ονομάτων χώρου μέσω κατάλληλης επέκτασης του πρωτοκόλλου EPP [<grRLS>](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW-9kD3pbQvBzPMvRUG00qNNMmp39_c2f17jYkRHenlud46AFRPGVw4KnX5YwyfF05tEYB4xCfsXaxS-VTkohbjBZZOl-YGWI0JSy9g%3D) για την προστασία των ονομάτων χώρoυ και των συσχετιζόμενων επαφών δικαιούχων από μη εξουσιοδοτημένες ενέργειες.

H διεύθυνση της υπηρεσίας EPP στο **δοκιμαστικό** περιβάλλον είναι:

-   [https://uat-regepp.ics.forth.gr:700/epp/proxy](https://uat-regepp.ics.forth.gr:700/epp/proxy)

Στο **παραγωγικό** περιβάλλον η υπηρεσία EPP είναι διαθέσιμη στην διεύθυνση:

-   [https://regepp.ics.forth.gr:700/epp/proxy](https://regepp.ics.forth.gr:700/epp/proxy)

Για τη διασύνδεση εφαρμογής πελάτη EPP με τα συστήματα του Μητρώου θα πρέπει να ακολουθήσετε τον [Οδηγό Yλοποίησης του πρωτοκόλλου EPP (v4.3)](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW8mXph_7m8uvqp3gd0T8qdgc8oxNS543D5Q3BcbY5Ya4ij-8-XpSLb-xzR2_3ZgspIXVmEDCmC9i7qB_A%3D%3D).

-   [Παραδείγματα EPP σε XML v4.3](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW8ubZZFRt1Q7Q-jWLbzDaqB7XZNw7VuJzCoHTVsOURzXn4iQiTlvmc1xuZpjl7QBWnlehNouAIm)
-   [Αναλυτικό παράδειγμα χρήσης της εφαρμογής](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW8fUdHkrkmNzA2k83ZbMvcvdtuJrq3T6Z1PLZEBDpx-_VZFv1xf1K-jn3vAYs6OV-Bj)
-   [Εφαρμογή και XML αρχεία](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW9WHDx3QFirvjTW4VAYV3nz_DCwbKNMlDSsMvh18tvJCIdLwQ_u2yqybEWdqCcelnvU)
-   [TLS Server Certificates](https://regweb.ics.forth.gr/hostmaster/documentation.html?topic=AQLTZW8aK0eQAUPqqMpuPLADLiB8zBg0JASiSNwy2O7IihaqGJNiK4L1WfJ5ouk0lwGY)

Το Μητρώο Ονομάτων Χώρου .gr και .ελ παρέχει στους Καταχωρητές κατάλληλο WHOIS APΙ (Application Programming Interface) υλοποιημένο πάνω από το πρωτόκολλο HTTPS και το αρχιτεκτονικό στυλ REST (Representational State Transfer) για την ανάκτηση στοιχείων ονόματος χώρου με κατάληξη .gr ή .ελ.

Επισημαίνουμε ότι δεν πραγματοποιούνται έλεγχοι διαθεσιμότητας ονόματος χώρου και δεν υποστηρίζεται η punycode μορφή. O έλεγχος διαθεσιμότητας παρέχεται από την υπηρεσία EPP και ειδικότερα την εντολή `<domain:check>`.

Για πρόσβαση στην υπηρεσία WHOIS παρακαλούμε αποστείλετε αίτημα δημιουργίας λογαριασμού χρήστη με δικαιώματα πρόσβασης στο WHOIS API και αναφέρετε τις στατικές διευθύνσεις IP που θα συνδέονται στην υπηρεσία. Εάν διαθέτετε ήδη πρόσβαση, η τεκμηρίωση του WHOIS RESTful API v1.0 είναι διαθέσιμη στους παρακάτω συνδέσμους:

-   [Δοκιμαστικό περιβάλλoν](https://uat-grwhois.ics.forth.gr:800/api/spec.html)

-   [Παραγωγικό περιβάλλον](https://grwhois.ics.forth.gr:800/api/spec.html)