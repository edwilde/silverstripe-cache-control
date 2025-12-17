From your working example:

```php
$videoTitle = TextField::create('VideoTitle', 'Video section title'),
$videoText = TextareaField::create('VideoText', 'Video section text')->setRows(2),
$videoURL = VideoField::create('VideoURL', 'Video URL'),
$videoImage = UploadField::create('VideoImage', 'Video image')
```

Then OUTSIDE the array:
```php
$videoTitle->displayIf('IncludeVideo')->isChecked();
$videoText->displayIf('IncludeVideo')->isChecked();
$videoURL->displayIf('IncludeVideo')->isChecked();
$videoImage->displayIf('IncludeVideo')->isChecked();
```

Key differences from what I did:
1. They assign fields WITH the comma inside the array definition
2. They don't call ->end()
3. They use isChecked() and it works

Let me check our actual field definitions...
