<?php

/**
 * Firebase Cloud Messaging icin servis hesap dosyalari.
 *
 * Eski config/firebase_projects.php ile AYNI harita. PushBildirimi::gonder() salon'un firebase_profile
 * kolonuna bakar, burada karsiligi olan dosyayi kullanir. Profile bos veya tanimsizsa 'default' kullanilir.
 *
 * Yalniz dosya ADI kullanilir: dizin config('bildirim_kuyrugu.firebase_anahtar_dizini')
 * (varsayilan storage/app/firebase).
 * JSON dosyalarinin kendisi git'e commit edilmez (gitignore'da olmali).
 *
 * Yeni marka eklemek icin:
 *  1) Firebase Console'dan service account JSON indir
 *  2) storage/app/firebase/{profile}.json olarak koy
 *  3) Bu listeye yeni satir ekle
 *  4) Salon kayitinda firebase_profile alanini bu isme set et
 */
return [
    // Eski "uygulamala" projesi (5ff4d) - cogu marka burada
    'default'      => 'app/firebase/randevumcepte-uygulamala-5ff4d-8a85c43832c1.json',
    'eymlife'      => 'app/firebase/randevumcepte-uygulamala-5ff4d-8a85c43832c1.json',
    'cerenceviz'      => 'app/firebase/randevumcepte-uygulamala-5ff4d-8a85c43832c1.json',
    'gulayersoy'      => 'app/firebase/randevumcepte-uygulamala-5ff4d-8a85c43832c1.json',
    'escaladeink'      => 'app/firebase/randevumcepte-uygulamala-5ff4d-8a85c43832c1.json',
    'realform'      => 'app/firebase/randevumcepte-uygulamala-5ff4d-8a85c43832c1.json',
    'senail'      => 'app/firebase/randevumcepte-uygulamala-5ff4d-8a85c43832c1.json',
    'esemavci'      => 'app/firebase/randevumcepte-uygulamala-5ff4d-8a85c43832c1.json',
    'vionna'      => 'app/firebase/randevumcepte-uygulamala-5ff4d-8a85c43832c1.json',
    'shemall'      => 'app/firebase/randevumcepte-uygulamala-5ff4d-8a85c43832c1.json',


    // NOT: "Genel" (ortak) app icin per-salon firebase_profile KULLANILMAZ.
    // Genel, tek app_bundle'i cok isletme paylasan bir mecra oldugundan Firebase
    // projesi BUNDLE-bazli cozulur -> config/firebase_bundle_projects.php.
    // (Marka appler asagidaki gibi salon-bazli profile devam eder.)

    // Salooncadde markasi - randevumcepte-uygulamalar projesinde
    'salooncadde'  => 'app/firebase/randevumcepte-uygulamalar-0d38a7fc2d78.json',
    'dermatouch'  => 'app/firebase/randevumcepte-uygulamalar-0d38a7fc2d78.json',
    'naturelkuafor'  => 'app/firebase/randevumcepte-uygulamalar-0d38a7fc2d78.json',
    'kessplus'  => 'app/firebase/randevumcepte-uygulamalar-0d38a7fc2d78.json',
    'bercislina'  => 'app/firebase/randevumcepte-uygulamalar-0d38a7fc2d78.json',
    'busrayilmaz'  => 'app/firebase/randevumcepte-uygulamalar-0d38a7fc2d78.json',
    'edasavut'  => 'app/firebase/randevumcepte-uygulamalar-0d38a7fc2d78.json',
    'fizyofly'  => 'app/firebase/randevumcepte-uygulamalar-0d38a7fc2d78.json',
    'clinaravip'  => 'app/firebase/randevumcepte-uygulamalar-0d38a7fc2d78.json',

    'aydangurece'  => 'app/firebase/egekobi-893bd4d005ff.json',
    'aydangurecce'  => 'app/firebase/egekobi-893bd4d005ff.json', // DB'de firebase_profile bu yazimla (cift c) kayitli
    'sirius'  => 'app/firebase/egekobi-893bd4d005ff.json',
    'successo'  => 'app/firebase/egekobi-893bd4d005ff.json',
    'moncher'  => 'app/firebase/egekobi-893bd4d005ff.json',
    'dilarasenses'  => 'app/firebase/egekobi-893bd4d005ff.json',
    'essa'  => 'app/firebase/egekobi-893bd4d005ff.json',
    'faya'  => 'app/firebase/egekobi-893bd4d005ff.json',
    'fulyakandemir'  => 'app/firebase/egekobi-893bd4d005ff.json',

    'shiningstars'=>'app/firebase/randevumcepte-5fd93-05a39b8b2f48.json',
    'aleynaguzellik'=> 'app/firebase/randevumcepte-5fd93-05a39b8b2f48.json',

];
